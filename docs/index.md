---
layout: home

hero:
  name: YOLO
  text: Deploy Laravel to AWS Fargate
  tagline: A CLI that provisions the AWS resources your Laravel app needs and ships zero-downtime container deploys — straight from your terminal or CI. Battle-tested on apps serving 2 million requests a day.
  image:
    src: /logo.png
    alt: YOLO
  actions:
    - theme: brand
      text: Get Started
      link: /guide/getting-started
    - theme: alt
      text: What is YOLO?
      link: /guide/what-is-yolo
    - theme: alt
      text: View on GitHub
      link: https://github.com/codinglabsau/yolo

features:
  - icon: "🚀"
    title: One Manifest, One Command
    details: Describe your app in yolo.yml, then `yolo sync` provisions the infrastructure and `yolo deploy` ships the code. No CloudFormation, Terraform, or Kubernetes.
  - icon: "♻️"
    title: Zero-Downtime Fargate Deploys
    details: Rolling ECS deployments with the deployment circuit breaker — a broken release fast-fails and auto-rolls-back. The ALB drains in-flight requests before each task stops.
  - icon: "🧭"
    title: Scope-First Provisioning
    details: Resources are grouped by blast radius — account, environment, and app. Each tier has a single writer, so shared infrastructure is never clobbered by an app sync.
  - icon: "📦"
    title: Batteries-Included Container
    details: One image runs FrankenPHP/Octane for web, queue workers, and the scheduler under supervisord. YOLO generates the entrypoint and process config for you.
  - icon: "🏢"
    title: Multi-Tenancy
    details: Declare tenants in your manifest and YOLO provisions isolated queues and DNS per tenant — with single-tenant cutovers via `--tenant`.
  - icon: "🔐"
    title: Keyless CI/CD
    details: Deploy from GitHub Actions with short-lived OIDC credentials — no AWS keys in repo secrets. YOLO provisions the deployer role and scopes its trust to your branch or tag.
---
