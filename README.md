# Hostbase AWS Importer

Import Amazon Web Services information into Hostbase.  If any of the hosts already exist, they will be updated.

## Installation

1. Download/clone this whole repository
2. Run `composer install` from the project root
3. Ensure aws-cli is installed

## Configuration

From the project root, create a config.ini:

```
accessKey = "APIKEY"
secretKey = "SUPERSECRETKEY"
hostbaseUrl = "http://your.hostbase.server"
filterRegex = "/ClientToken/"
baseDomain = "mydomain.com"
```

Note: "Tags" is automatically added to filterRegex, they are added as Key Value pairs not as an Array like returned by AWS API

## Run

1. `chmod +x bin/hostbase-importer-aws`
2. `bin/hostbase-importer-aws`
