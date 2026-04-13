# CI/CD

YOLO integrates with CI pipelines like GitHub Actions for automated deployments.

## Provisioning CI Resources

```bash
yolo sync:ci <environment>
```

## GitHub Actions Example

Tag your releases with a date-based naming convention and forward the tag to the deploy command:

```yaml
- name: Deploy
  run: php vendor/bin/yolo deploy production --app-version=${{ github.event.release.tag_name }}
  env:
    AWS_ACCESS_KEY_ID: ${{ secrets.PRODUCTION_AWS_ACCESS_KEY_ID }}
    AWS_SECRET_ACCESS_KEY: ${{ secrets.PRODUCTION_AWS_SECRET_ACCESS_KEY }}
```

In CI environments, YOLO uses `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` instead of profile-based authentication. Ensure these keys use least-privileged scope.
