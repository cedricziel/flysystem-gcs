# flysystem-gcs - Flysystem Adapter for Google Cloud Storage

[![build status](https://gitlab.com/cedricziel/flysystem-gcs/badges/master/build.svg)](https://gitlab.com/cedricziel/flysystem-gcs/commits/master) [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/cedricziel/flysystem-gcs/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/cedricziel/flysystem-gcs/?branch=master) [![Code Coverage](https://scrutinizer-ci.com/g/cedricziel/flysystem-gcs/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/cedricziel/flysystem-gcs/?branch=master)

Flysystem Adapter for Google cloud storage using the gcloud PHP library

## How-To

```php
use CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter;
use League\Flysystem\Filesystem;

$adapterOptions = [
    'projectId' => 'my-project-id',
    'bucket'    => 'my-project-bucket.appspot.com',
    'prefix'    => 'my-path-prefix/inside/the/bucket',
];
$adapter = new GoogleCloudStorageAdapter(null, $adapterOptions);

$filesystem = new Filesystem($adapter);
```

## Demo

There's [a demo project](https://github.com/cedricziel/flysystem-gcs-demo) that shows simple operations in a file system manager.

## Development

Some tests require actual access to GCS. They can be configured through
the environment.

| variable | meaning |
|----------|---------|
| GOOGLE_APPLICATION_CREDENTIALS | absolute path to the service account credentials *.json file |
| GCLOUD_BUCKET | name of the bucket to perform the tests on |
| GCLOUD_PROJECT | the cloud project to use |

## License

MIT
