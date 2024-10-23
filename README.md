# YOLO

YOLO is a deployment tool inspired by Laravel Vapor CLI. 

YOLO sits approximately in between Laravel Forge and Laravel Vapor. It creates highly available resources on AWS, but uses EC2 instead of Lambda.

The goal is to heavily leverage AWS, and support moderate to very heavy traffic web apps. 

___
### Prerequisites
YOLO uses AWS profiles for authentication, so before getting started install the AWS CLI tool.

## Installation

### Install With Composer
```bash
composer require codinglabsau/yolo
```

Optionally, install globally with:
```bash
composer global require codinglabsau/yolo
```

You should also gitignore the `.yolo` build directory.

## Usage
See `vendor/bin/yolo` or `yolo` if you have installed globally.

### Authentication
YOLO uses AWS profiles stored in `~/.aws/credentials` for authentication. You'll want to set a YOLO_{ENVIRONMENT}_AWS_PROFILE in the app `.env` file to point to the correct profile; eg. `YOLO_PRODUCTION_AWS_PROFILE=my-project-profile`.

Once configured, all future operations will leverage the profile.

Note that for CI environments, AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY are used instead. Ensure that any access keys provided in CI are using least-privileged scope as a safety guard against accidental AWS resource modification.

### `yolo init`
Initalises the yolo.yml file in the app with a production environment, and ensures the low level AWS resources exist. 

### `yolo build <environment>`
Prior to building the environment, you'll need to create `.env.<environment>` followed by `yolo env:push <environment>`.

With the .env.<environment> file in place, the build command takes care of building a deployment ready directory. 

## Credits
- [Steve Thomas](https://github.com/stevethomas)
- [All Contributors](https://github.com/codinglabsau/yolo/contributors)

## License
Proprietary.
