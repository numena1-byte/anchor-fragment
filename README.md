# anchor-fragment
Commentary anchor plugin for Wordpress. Focused on Vatican documents for now. Local for individual sites and open to other sites via AT Protocol.

## Layout

* `anchor-fragment/` – the WordPress plugin (this folder is what gets installed)
* `tests/run.php`, `tests/match.php`, `tests/ingest.php` – local tests for the parser and fragment builder, the matcher, and the multi-page fetch (`php tests/run.php anchor-fragment`, and so on)
* `dist/anchor-fragment.zip` – built copy of the plugin, used to install on a staging site with `wp plugin install <raw URL pinned to a commit> --force`. It is build output: rebuild it from `anchor-fragment/` whenever the plugin changes.

## Status

Prototype. Staging only.

* Fetches Vatican documents on the server, finds numbered paragraphs and builds `#:~:text=` links to them.
* Matches quotations in posts to the paragraph they came from, using a hashed index of 5-word runs.
* 11 single-page documents (encyclicals, Vatican II texts, Dilexi te) plus the Catechism, which is about 370 pages and is fetched from the test page in a resumable job.
* Not yet: canon law (locators like "Can. 1234 §2"), other languages.
