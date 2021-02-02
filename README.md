# flysystem-gcs - Flysystem Adapter for Google Cloud Storage

[![Build Status](https://travis-ci.org/cedricziel/flysystem-gcs.svg?branch=master)](https://travis-ci.org/cedricziel/flysystem-gcs) [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/cedricziel/flysystem-gcs/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/cedricziel/flysystem-gcs/?branch=master) [![Code Coverage](https://scrutinizer-ci.com/g/cedricziel/flysystem-gcs/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/cedricziel/flysystem-gcs/?branch=master)

Flysystem Adapter for Google cloud storage using the gcloud PHP library

## Installation

Using composer:

```
composer require cedricziel/flysystem-gcs
```

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

## Authentication

This library utilizes the PHP library [`google/cloud`](https://github.com/GoogleCloudPlatform/google-cloud-php), which in turn uses [google/auth](https://github.com/google/google-auth-library-php).

Why is this important? It's important, because if you're authenticated
locally, through the `gcloud` command-line utility, or running on a 
Google Cloud Platform VM, in many cases you are already authenticated,
and you don't need to do anything at all, in regards to authentication.

For any other case, you will most probably want to export the environment 
variable `GOOGLE_APPLICATION_CREDENTIALS` with a value of the absolute 
path to your service account credentials that is authorized to use
the `Storage: Full Access` oAuth2 scope, and you're all set.

All examples, including tests, make use of this behaviour.

If that's not what you want, you can create your own `StorageClient` object
that's authenticated differently and pass it to the adapter class constructor
as first argument.

## Demo

There's [a demo project](https://github.com/cedricziel/flysystem-gcs-demo) that shows simple operations in a file system manager.

## Public URLs to StorageObjects

The Adapter ships with 2 different methods to generate public URLs:

* a flysystem plugin that exposes a `getUrl($path)` method on your
  `FilesystemInterface` instance
* a `getUrl($path)` method on the adapter itself to generate the URL

Read below to know when you will want to use the one or the other.

### Flysystem Plugin

The standard way to generate public urls with this adapter would be to
add a flysystem plugin to your `FilesystemInterface` instance.

The plugins **needs** a piece of configuration, telling it whether and
which bucket to use in combination with the standard accessible
`https://storage.googleapis.com/bucket/object.url`, or with a custom
[CNAME URL](https://cloud.google.com/storage/docs/xml-api/reference-uris#cname)
such as `http://storage.my-domain.com`.

Notice that GCS public access via CNAMEs is `http` only (no `https`).

**Example: Standard public URL to bucket**

Supposed you have a bucket `my-application-bucket`, configure the plugin
as follows:

```php
// create your adapter as usual
$adapter = new GoogleCloudStorageAdapter(..);
// add it to your `FilesystemInterface` instance
$filesystem = new Filesystem($adapter);

// create your configuration
$path = 'text-object.txt';
$config = ['bucket' => 'my-application-bucket'];
$filesystem->addPlugin(new GoogleCloudStoragePublicUrlPlugin($config));
$publicUrl = $filesystem->getUrl($path);

// $publicUrl == 'https://storage.googleapis.com/my-application-bucket/text-object.txt';
```

**Example: Standard public URL to bucket with directory prefix**

Supposed you have a bucket `my-application-bucket` with a directory prefix 
of `my/prefix`, append the prefix separated by a slash to the bucket name:

```php
// create your adapter as usual
$adapter = new GoogleCloudStorageAdapter(null, ['prefix' => 'my/prefix', ...]);
// add it to your `FilesystemInterface` instance
$filesystem = new Filesystem($adapter);

// create your configuration
$path = 'text-object.txt';
$config = ['bucket' => 'my-application-bucket/my/prefix'];
$filesystem->addPlugin(new GoogleCloudStoragePublicUrlPlugin($config));
$publicUrl = $filesystem->getUrl($path);

// $publicUrl == 'https://storage.googleapis.com/my-application-bucket/my/prefix/text-object.txt';
```

**Example: Custom domain to bucket**

Supposed you have setup a CNAME `assets.example.com` pointing to the public
endpoint mentioned in the [documentation](https://cloud.google.com/storage/docs/xml-api/reference-uris#cname), you would configure
the plugin as follows:

```php
// create your adapter as usual
$adapter = new GoogleCloudStorageAdapter(..);
// add it to your `FilesystemInterface` instance
$filesystem = new Filesystem($adapter);

// create your configuration
$path = 'text-object.txt';
$config = ['url' => 'http://assets.example.com'];
$filesystem->addPlugin(new GoogleCloudStoragePublicUrlPlugin($config));
$publicUrl = $filesystem->getUrl($path);

// $publicUrl == 'http://assets.example.com/text-object.txt'
```

**Example: Custom domain to bucket with directory prefix**

Supposed you have setup a CNAME `assets.example.com` pointing to the public
endpoint mentioned in the [documentation](https://cloud.google.com/storage/docs/xml-api/reference-uris#cname), and your filesystem uses 
a directory prefix of `my/prefix` you need to append the prefix to the 
`url` in the configuration and would configure the plugin as follows:

```php
// create your adapter as usual
$adapter = new GoogleCloudStorageAdapter(null, ['prefix' => 'my/prefix', ...]);
// add it to your `FilesystemInterface` instance
$filesystem = new Filesystem($adapter);

// create your configuration
$path = 'text-object.txt';
$config = ['url' => 'http://assets.example.com/my/prefix'];
$filesystem->addPlugin(new GoogleCloudStoragePublicUrlPlugin($config));
$publicUrl = $filesystem->getUrl($path);

// $publicUrl == 'http://assets.example.com/my/prefix/text-object.txt'
```

### `getUrl` on the adapter / Laravel 5

The Storage services used in Laravel 5 do not use flysystem plugins.

The Laravel 5 specific flysystem instance checks if there's a `getUrl`
method on the adapter object.

This method is implemented on the Adapter, which is why you can add the
adapter directly and use it right away:

```php
// create the adapter
Storage::extend('gcs', function($app, $config) {
    $adapter = new GoogleCloudStorageAdapter(null, ['bucket' => 'my-bucket', ...]);
    // add it to your `FilesystemInterface` instance
    return new Filesystem($adapter);
});

// register a new disk of type 'gcs' and name it 'gcs'

// use it
$gcs = Storage::disk('gcs');
$path = 'test-laravel.txt';
$gcs->put($path, 'test-content', AdapterInterface::VISIBILITY_PUBLIC);

$publicUrl = $gcs->url($path);
// $publicUrl == 'https://storage.googleapis.com/my-application-bucket/test-laravel.txt';
```

## Development

Some tests require actual access to GCS. They can be configured through
the environment.

| variable | meaning |
|----------|---------|
| GOOGLE_APPLICATION_CREDENTIALS | absolute path to the service account credentials *.json file |
| GOOGLE_CLOUD_BUCKET  | name of the GCS bucket to perform the tests on |
| GOOGLE_CLOUD_PROJECT | the cloud project id to use |

## License

MIT
