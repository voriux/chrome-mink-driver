Contributing
============

Feel free to fork and improve.

You can run the tests locally using the docker image

```bash
cd chrome-headless
docker run --rm -it -v $(pwd):/code registry.gitlab.com/dmore/docker-chrome-headless bash
```

Then in the shell:

```bash
composer install
vendor/bin/phpunit
```
