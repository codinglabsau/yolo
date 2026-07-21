# What is YOLO?

YOLO is a CLI tool that lives inside your Laravel app at `vendor/bin/yolo`. It provisions the AWS resources your application needs and ships zero-downtime container deployments — all driven from a single manifest file, `yolo.yml`.

Under the hood it's a Symfony Console application that talks to the AWS SDK directly. There's no CloudFormation, Terraform, Kubernetes, or Elastic Beanstalk in the middle — YOLO reads your manifest, looks at what already exists in your account, and makes the minimal set of API calls to reconcile reality with what you've declared.

## The mental model

Everything in YOLO comes down to two verbs over one manifest:

| | What it does | When you run it |
|---|---|---|
| **`yolo sync`** | Reconciles your **infrastructure** — VPC, load balancer, ECS cluster, IAM roles, S3, DNS, certificates. | When infrastructure changes (first setup, new manifest keys, scaling). |
| **`yolo deploy`** | Builds your **container image** and rolls it out to the running service with zero downtime. | Every time you ship code. |

`yolo.yml` is the single source of truth. You describe your app once — its name, environments, domains, container resources — and both verbs read from it.

## How a request is served

YOLO deploys to **AWS Fargate** (serverless ECS containers). A deployed app looks like this:

```
Internet → Route 53 → Application Load Balancer → ECS Fargate task(s)
                                                    └─ supervisord
                                                       ├─ FrankenPHP / Octane  (web)
                                                       ├─ queue:work           (optional)
                                                       └─ scheduler (cron)     (optional)
```

A single container image runs the web server, queue workers, and the scheduler together under [supervisord](https://supervisord.org/). YOLO generates the entrypoint and process configuration at build time, so your Dockerfile stays small — see [The Container Image](/guide/images).

> The earlier EC2/ASG/CodeDeploy generation of YOLO lives on as the separate [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha) package. This documentation covers the **Fargate** rewrite — there is no EC2, autoscaling group, or AMI here.

## Ownership scopes

YOLO groups every resource it manages by **blast radius** — who is affected if it changes:

- **Account** — account-global, shared by every environment (e.g. the service-linked roles AWS services require, the GitHub OIDC provider).
- **Environment** — shared by every app in one environment (e.g. the VPC, subnets, load balancer, shared IAM roles).
- **App** — belongs to one app in one environment (e.g. its ECS service, task definition, CloudFront distribution).

Each scope has a single writer (`sync:account`, `sync:environment`, `sync:app`), so an app deploy can never clobber shared infrastructure. This is explained in full under [Provisioning](/guide/provisioning).

## Who is it for?

YOLO is for PHP developers who are comfortable owning their AWS footprint with an infrastructure-as-code mindset. It has underpinned large, mission-critical production applications — but it's a control plane you operate, not a set-and-forget platform.

## Disclaimer

Use YOLO at your own risk. It goes without saying, but we'll say it anyway.

---

Ready to ship? Head to [Getting Started](/guide/getting-started) — you can have an app live on Fargate in under an hour.
