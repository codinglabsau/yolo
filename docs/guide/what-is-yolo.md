# What is YOLO?

YOLO is a CLI tool that lives inside your Laravel app at `vendor/bin/yolo`. It provisions and configures all required AWS resources, and handles building and deploying your application with zero downtime.

At its core, YOLO is a Symfony Console application that leverages the AWS SDK directly — no CloudFormation, Terraform, Kubernetes, or Elastic Beanstalk.

## Who is it for?

YOLO is designed for PHP developers who are comfortable managing AWS using an infrastructure-as-code approach.

While YOLO has underpinned very large, mission-critical production applications, it is not a set-and-forget solution. It acts as a control plane that allows you to manage and expand your AWS footprint over time.

## Features

- **Autoscaling Worker Groups** — ALB with autoscaling groups for web, queue, and scheduler. Self-healing instances with automatic burst scaling.
- **Resource Sharing** — Share VPCs, subnets, and other resources between applications to reduce costs.
- **Zero-Downtime Deployments** — AWS CodeDeploy handles rolling deployments from the CLI or CI.
- **Multi-Tenancy** — Define tenants in your manifest and YOLO provisions resources for each one.
- **Environment Management** — Push and pull `.env` files to S3 with diff previews.
- **S3 Integration** — Build artefacts and user data file storage.
- **Octane Support** — Experimental support for Laravel Octane.
- **Video Transcoding** — AWS Elemental MediaConvert integration.
- **Least Privilege IAM** — Strong permission segregation across environments and apps.
- **MySQL Backups** — Scheduled `mysqldump` backups.

## Disclaimer

Use YOLO at your own risk. It goes without saying, but we'll say it anyway.
