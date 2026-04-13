# Environment Files

Environment files are stored in the S3 artefacts bucket and retrieved during deployment.

## Initial Setup

If you have an existing `.env` file, copy it to `.env.<environment>` in the root of your app. Otherwise, use the stub provided by `yolo init`.

## Push

Push your environment file to S3:

```bash
yolo env:push <environment>
```

## Pull

Retrieve the current environment file from S3:

```bash
yolo env:pull <environment>
```

This is useful for reviewing the current production configuration or making changes.
