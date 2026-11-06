# Shakewell Clone Posts

Free, open source (GPL-2.0-or-later) WordPress plugin: Clone and New Draft for posts, pages and custom post types. Behaviour, filters and FAQ are in `readme.txt`.

```sh
composer install
npm ci
npm run env:start      # WordPress at http://localhost:8894 (admin / password), needs Docker
npm test               # PHPUnit inside the WordPress test container
composer lint          # WordPress coding standards
npm run plugin-check   # the official Plugin Check
```

Plugin Check flags dev files (`phpcs.xml.dist`, dotfiles) in the working tree. They are excluded from release zips by `.distignore`.

Release: bump the version in the plugin header, `readme.txt` and the `VERSION` constant, add a changelog line, then tag `vX.Y.Z`.
