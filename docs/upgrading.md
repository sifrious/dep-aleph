# Upgrade Aleph

Update Composer dependencies, run migrations, and ask Aleph to check the installed state:

```bash
composer update sifrious/aleph sifrious/funes
php artisan migrate
php artisan aleph:upgrade:check
```

The check fails with a list when an Aleph migration is absent, a required top-level configuration
key is unavailable, or a registered connector no longer satisfies its identity, configuration, and
manifest contracts. Use `--json` in deployment automation.

Review these files before each upgrade:

- `database/migrations/` for new operational tables or columns
- `config/aleph.php` for new package defaults
- `docs/capabilities/` for changed connector inputs
- `docs/connector-support.md` for verification status and optional host tools

Aleph merges package defaults at runtime, but a published `config/aleph.php` may still omit comments
or explicit values added in a newer release. Compare the published file when configuration behavior
changes. Never copy credential material into that file. Connector tokens stay behind opaque host
references.
