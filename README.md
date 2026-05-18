<p align="center">
  <img src="art/logo.png" alt="YOLO" height="80">
</p>

# YOLO

YOLO deploys Laravel applications to AWS.

> [!IMPORTANT]
> **YOLO is mid-pivot from v1 (EC2/ASG) to v2 (Fargate/ECS).** v1 lives on the [`1.x`](https://github.com/codinglabsau/yolo/tree/1.x) branch in maintenance mode. v2 development is happening on `main` — currently an empty skeleton, commands land via the [Linear project MVP milestone](https://linear.app/codinglabsau/project/yolo-v2-f26af789f353). See [STATUS.md](STATUS.md) for details.

## Composer pinning

For an existing v1 consumer (production):

```json
{
  "require": {
    "codinglabsau/yolo": "v1.0.0-alpha.34"
  }
}
```

For new apps or v2 canaries:

```json
{
  "require": {
    "codinglabsau/yolo": "dev-main"
  }
}
```

## v2 in one line

```bash
yolo init && yolo build && yolo sync production && yolo deploy production
```

That's the goal. Today the `Yolo` class registers zero commands — it's a placeholder while v2 is being built. Track progress in the [Linear project](https://linear.app/codinglabsau/project/yolo-v2-f26af789f353).

## v1 documentation

The v1 (EC2/ASG) documentation lives on the [`1.x` branch](https://github.com/codinglabsau/yolo/tree/1.x). LP and other v1 consumers should reference that branch.

## Contributing

v1 (`1.x`): bug fixes only. Pull requests targeting `1.x` are welcome for production-safe patches. No new features.

v2 (`main`): in active development. Open issues against the [Linear project](https://linear.app/codinglabsau/project/yolo-v2-f26af789f353) or coordinate with @stevethomas before submitting PRs.

## License

MIT — see [LICENSE.md](LICENSE.md).
