<p align="center">
  <img src="art/logo.png" alt="YOLO" height="80">
</p>

# YOLO

YOLO deploys Laravel applications to AWS Fargate.

> [!IMPORTANT]
> This package is in active development - contributions are welcome!

## Composer pinning

For new apps and Fargate canaries:

```json
{
  "require": {
    "codinglabsau/yolo": "dev-main"
  }
}
```

For existing EC2/ASG consumers (frozen, maintenance-only):

```json
{
  "require": {
    "codinglabsau/yolo-alpha": "v1.0.0-alpha.34"
  }
}
```

Consumers migrating from `yolo-alpha` to `yolo` 1.0 install both packages side-by-side during the cutover window, then drop `yolo-alpha` once on Fargate.

## YOLO 1.0 in one line

```bash
yolo init && yolo sync production && yolo deploy production
```

`yolo deploy` builds and pushes the image, then ships it. Pass `--app-version=<tag>` (e.g. a GitHub release name) to stamp the build with a specific tag instead of an auto-generated timestamp.

## Pre-1.0 alpha documentation

The EC2/ASG `yolo-alpha` documentation lives in its own repo: [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha). Existing consumers should reference that repo.

## Contributing

`yolo-alpha`: bug fixes only. Pull requests against [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha) are welcome for production-safe patches. No new features.

`yolo` (this repo, `main`): in active development. Open an issue on this repo or coordinate with @stevethomas before submitting PRs.

## License

MIT — see [LICENSE.md](LICENSE.md).
