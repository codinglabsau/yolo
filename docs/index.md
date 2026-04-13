---
layout: home

hero:
  name: YOLO
  text: Deploy Laravel to AWS
  tagline: A CLI tool for provisioning and deploying high-availability PHP applications on AWS. Battle-tested on apps serving 2 million requests per day.
  image:
    src: /logo.png
    alt: YOLO
  actions:
    - theme: brand
      text: Get Started
      link: /guide/getting-started
    - theme: alt
      text: View on GitHub
      link: https://github.com/codinglabsau/yolo

features:
  - icon: "🔄"
    title: Zero-Downtime Deployments
    details: Leverages AWS CodeDeploy for seamless deployments from your local machine or CI pipeline.
  - icon: "📈"
    title: Autoscaling Worker Groups
    details: Provisions ALB and autoscaling groups for web, queue, and scheduler workers. Self-healing and burst-ready.
  - icon: "🏢"
    title: Multi-Tenancy
    details: Define tenants in your manifest and YOLO provisions isolated resources for each one.
  - icon: "🔐"
    title: Environment Management
    details: Push and pull .env files to S3 with diff previews before deploying.
  - icon: "🎬"
    title: Video Transcoding
    details: Configure AWS Elemental MediaConvert for video processing workloads.
  - icon: "⚡"
    title: Octane Support
    details: Experimental support for Laravel Octane for turbocharged PHP applications.
---
