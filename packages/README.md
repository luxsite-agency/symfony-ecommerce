# packages/

Composer path repository (`packages/*/*` in composer.json) for optional add-on packages, see docs/addons.md.
The engine installs and passes its tests without any of them. Dockerfile.prod copies this directory before `composer install`.
