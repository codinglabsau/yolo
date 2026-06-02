<p align="center">
  <img src="art/logo.png" alt="YOLO" height="80">
</p>

# YOLO

YOLO deploys Laravel applications to AWS Fargate.

> [!IMPORTANT]
> This package is in active development — contributions are welcome!

## Documentation

Everything lives at **[codinglabsau.github.io/yolo](https://codinglabsau.github.io/yolo/)**:

- [Getting Started](https://codinglabsau.github.io/yolo/guide/getting-started) — a Laravel app live on Fargate in under an hour
- [Provisioning](https://codinglabsau.github.io/yolo/guide/provisioning) and [Building & Deploying](https://codinglabsau.github.io/yolo/guide/building-and-deploying)
- [Deploying from GitHub Actions](https://codinglabsau.github.io/yolo/guide/ci-cd) — keyless OIDC, plus the `--check` drift gate for CI
- [Command reference](https://codinglabsau.github.io/yolo/reference/commands) and [manifest reference](https://codinglabsau.github.io/yolo/reference/manifest)

The docs are a VitePress site under [`docs/`](docs/) and deploy to GitHub Pages on every push to `main`.

## Installation

```json
{
  "require": {
    "codinglabsau/yolo": "dev-main"
  }
}
```

Existing EC2/ASG consumers stay on the frozen, maintenance-only [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha) (`v1.0.0-alpha.34`). Migrating to Fargate? Install both side-by-side during the cutover, then drop `yolo-alpha`.

## Contributing

`yolo` (this repo, `main`): in active development. Open an issue or coordinate with @stevethomas before submitting PRs.

`yolo-alpha`: bug fixes only — production-safe patches welcome on [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha). No new features.

## License

MIT — see [LICENSE.md](LICENSE.md).
