<p align="center">
  <img src="art/logo.png" alt="YOLO" height="80">
</p>

# YOLO

YOLO deploys Laravel applications to AWS Fargate.

> [!IMPORTANT]
> **YOLO 1.0 is in active development.** `main` is currently an empty skeleton — commands land via the [Linear project MVP milestone](https://linear.app/codinglabsau/project/yolo-v2-f26af789f353). The pre-1.0 EC2/ASG codebase has been extracted to [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha) (frozen, maintenance-only). See [STATUS.md](STATUS.md) for details.

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

Consumers migrating from `yolo-alpha` to `yolo` 1.0 require both side-by-side during the cutover window. See [docs/migrating-from-alpha.md](docs/migrating-from-alpha.md).

## YOLO 1.0 in one line

```bash
yolo init && yolo build && yolo sync production && yolo deploy production
```

That's the goal. Today the `Yolo` class registers zero commands — it's a placeholder while 1.0 is being built. Track progress in the [Linear project](https://linear.app/codinglabsau/project/yolo-v2-f26af789f353).

## Pre-1.0 alpha documentation

The EC2/ASG `yolo-alpha` documentation lives in its own repo: [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha). Existing consumers should reference that repo.

## Contributing

`yolo-alpha`: bug fixes only. Pull requests against [`codinglabsau/yolo-alpha`](https://github.com/codinglabsau/yolo-alpha) are welcome for production-safe patches. No new features.

`yolo` (this repo, `main`): in active development. Open issues against the [Linear project](https://linear.app/codinglabsau/project/yolo-v2-f26af789f353) or coordinate with @stevethomas before submitting PRs.

## License

MIT — see [LICENSE.md](LICENSE.md).
