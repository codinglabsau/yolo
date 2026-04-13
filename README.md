<p align="center">
  <img src="art/logo.png" alt="YOLO" height="80">
</p>

<p align="center">
  <a href="https://github.com/codinglabsau/yolo/actions/workflows/test.yml"><img src="https://github.com/codinglabsau/yolo/actions/workflows/test.yml/badge.svg" alt="Test"></a>
  <a href="https://github.com/codinglabsau/yolo/actions/workflows/analyse.yml"><img src="https://github.com/codinglabsau/yolo/actions/workflows/analyse.yml/badge.svg" alt="Analyse"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/License-MIT-blue.svg" alt="License: MIT"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4.svg" alt="PHP 8.3+"></a>
  <a href="https://codinglabsau.github.io/yolo/"><img src="https://img.shields.io/badge/Docs-VitePress-646cff.svg" alt="Docs"></a>
</p>

> [!IMPORTANT]
> This package is in active development - contributions are welcome!

YOLO helps you deploy high-availability PHP applications to AWS. It provisions and manages all required infrastructure (VPC, ALB, EC2, autoscaling, CodeDeploy, S3, IAM) and handles zero-downtime deployments from your local machine or CI pipeline.

Battle-tested on apps serving 2 million requests per day.

## Quick Start

```bash
composer require codinglabsau/yolo
yolo init
yolo sync production --dry-run
```

See the [full documentation](https://codinglabsau.github.io/yolo/) for provisioning, deployment, multi-tenancy, and configuration guides.

## Development

To work on YOLO locally, add a path repository to the consuming app's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "/Users/username/code/yolo"
    }
]
```

Then run `composer require codinglabsau/yolo:dev-main` to symlink the local package.

## Credits

- [Steve Thomas](https://github.com/stevethomas)
- [All Contributors](https://github.com/codinglabsau/yolo/contributors)

## License

MIT
