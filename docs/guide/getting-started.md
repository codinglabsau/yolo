# Getting Started

## Prerequisites

- PHP 8.3+
- An AWS account with administrative permissions
- Your domains added to Route53 on the same AWS account
- AWS credentials configured in `~/.aws/credentials`

## Installation

Install YOLO via Composer:

```bash
composer require codinglabsau/yolo
```

The CLI is available at `vendor/bin/yolo`, or just `yolo` if `./vendor/bin` is in your PATH.

Run `yolo` to see all available commands.

## Initialisation

Run `yolo init` to set up your project. This will:

1. Create a `yolo.yml` manifest with a boilerplate production environment
2. Add entries to `.gitignore`
3. Prompt for initial configuration values

After initialising, customise the `yolo.yml` manifest to suit your application.

## AWS Authentication

YOLO uses AWS profiles for authentication. Set a profile for each environment in your app's `.env` file:

```bash
YOLO_PRODUCTION_AWS_PROFILE=my-project-profile
```

For CI environments like GitHub Actions, use `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` instead. Ensure CI access keys use least-privileged scope.

## Next Steps

With YOLO installed and initialised, you're ready to:

1. [Provision AWS resources](/guide/provisioning)
2. [Create a server image](/guide/images)
3. [Set up your environment file](/guide/environment-files)
4. [Build and deploy](/guide/building-and-deploying)
