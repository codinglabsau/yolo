<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Contracts\AdminCommand;
use Codinglabs\Yolo\Resources\Iam\AdminsGroup;
use Codinglabs\Yolo\Resources\Iam\DeployersGroup;
use Codinglabs\Yolo\Resources\Iam\ObserversGroup;
use Codinglabs\Yolo\Resources\Iam\AssumeRoleGroup;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Resources\Iam\AppObserversGroup;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

/**
 * Manage a team member's access to this app + environment by editing which YOLO
 * grant groups they belong to — membership is the entire access lever (add to
 * grant a tier, remove to revoke). Runs in an app's manifest directory like
 * deploy/scale: it offers the env-wide tiers (observer, admin) plus the per-app
 * tiers for THIS app (observer, deployer). To grant deploy on another app, run it
 * in that app's directory.
 *
 * Admin-tier: it assumes the env admin role, whose policy can manage yolo-*
 * group membership — so a member of yolo-{env}-admins can grant access to others.
 * YOLO never creates or deletes the IAM users themselves.
 *
 *   yolo permissions production
 */
class PermissionsCommand extends Command implements AdminCommand
{
    protected function configure(): void
    {
        $this
            ->setName('permissions')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('Grant or revoke a team member\'s access by editing their YOLO group membership');
    }

    public function handle(): int
    {
        $grants = $this->provisionedGrants();

        if ($grants === []) {
            note(sprintf(
                "No YOLO grant groups exist for '%s' yet. Run `yolo sync %s` first to provision them.",
                Helpers::environment(),
                Helpers::environment(),
            ));

            return self::SUCCESS;
        }

        $users = collect(IamClient::users())
            ->pluck('UserName')
            ->sort()
            ->values();

        if ($users->isEmpty()) {
            note('No IAM users found in this account — YOLO grants access to existing IAM users, it never creates them.');

            return self::SUCCESS;
        }

        intro(sprintf('Manage access for %s · %s', Manifest::name(), Helpers::environment()));

        $user = select(
            label: 'Which team member?',
            options: $users->all(),
            scroll: 15,
        );

        $current = IamClient::groupsForUser($user);

        $options = collect($grants)->mapWithKeys(
            fn (array $grant): array => [$grant['name'] => $grant['label']]
        )->all();

        $selected = multiselect(
            label: sprintf('Tiers for %s (space toggles, enter confirms)', $user),
            options: $options,
            default: array_values(array_intersect(array_keys($options), $current)),
            scroll: 10,
            hint: 'Checked = granted. Unchecking revokes.',
        );

        $changes = static::membershipChanges(array_keys($options), $current, $selected);

        if ($changes['add'] === [] && $changes['remove'] === []) {
            info(sprintf('No changes — %s already has exactly those tiers.', $user));

            return self::SUCCESS;
        }

        $labelFor = fn (string $group): string => $options[$group] ?? $group;

        table(
            ['Action', 'Tier'],
            [
                ...array_map(fn (string $g): array => ['grant', $labelFor($g)], $changes['add']),
                ...array_map(fn (string $g): array => ['revoke', $labelFor($g)], $changes['remove']),
            ],
        );

        if (! confirm(sprintf('Apply these access changes for %s?', $user), default: false)) {
            note('No changes made.');

            return self::SUCCESS;
        }

        foreach ($changes['add'] as $group) {
            Aws::iam()->addUserToGroup(['UserName' => $user, 'GroupName' => $group]);
        }

        foreach ($changes['remove'] as $group) {
            Aws::iam()->removeUserFromGroup(['UserName' => $user, 'GroupName' => $group]);
        }

        info(sprintf(
            '%s: granted %d, revoked %d. Access takes effect on their next assume (tokens last ~1h).',
            $user,
            count($changes['add']),
            count($changes['remove']),
        ));

        return self::SUCCESS;
    }

    /**
     * The candidate grants for this app + environment, gated by what makes sense:
     * the per-app deployer only exists when the app has a deployer role (a GitHub
     * repository). Pure — depends only on the manifest, no AWS calls.
     *
     * @return array<int, array{name: string, label: string, group: AssumeRoleGroup}>
     */
    public function grants(): array
    {
        $app = Manifest::name();

        $grants = [
            [
                'group' => new ObserversGroup(),
                'label' => 'Observer — entire environment (read every app)',
            ],
            [
                'group' => new AppObserversGroup(),
                'label' => sprintf('Observer — %s only (read this app, logs fenced)', $app),
            ],
        ];

        if (Helpers::githubRepository() !== null) {
            $grants[] = [
                'group' => new DeployersGroup(),
                'label' => sprintf('Deployer — %s (deploy this app)', $app),
            ];
        }

        $grants[] = [
            'group' => new AdminsGroup(),
            'label' => 'Admin — entire environment (sync / scale / manage access)',
        ];

        return array_map(fn (array $grant): array => [
            'name' => $grant['group']->name(),
            'label' => $grant['label'],
            'group' => $grant['group'],
        ], $grants);
    }

    /**
     * The candidate grants narrowed to the groups that are actually provisioned —
     * you can't grant a tier whose group hasn't been synced yet.
     *
     * @return array<int, array{name: string, label: string, group: AssumeRoleGroup}>
     */
    protected function provisionedGrants(): array
    {
        return array_values(array_filter(
            $this->grants(),
            fn (array $grant): bool => $grant['group']->exists(),
        ));
    }

    /**
     * The membership diff: which offerable groups to add (selected, not current)
     * and which to remove (offerable + current, but unselected). Only ever touches
     * the offerable (YOLO-managed) set — a user's non-YOLO group memberships are
     * never disturbed.
     *
     * @param  array<int, string>  $offerable
     * @param  array<int, string>  $current
     * @param  array<int, string>  $selected
     * @return array{add: array<int, string>, remove: array<int, string>}
     */
    public static function membershipChanges(array $offerable, array $current, array $selected): array
    {
        return [
            'add' => array_values(array_diff($selected, $current)),
            'remove' => array_values(array_diff(array_intersect($offerable, $current), $selected)),
        ];
    }
}
