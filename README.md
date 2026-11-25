# anchor-fragment
Commentary anchor plugin for Wordpress. Focused on Vatican documents for now. Local for individual sites and open to other sites via AT Protocol.

## Layout

* `anchor-fragment/` – the WordPress plugin (this folder is what gets installed)
* `tests/run.php`, `tests/match.php`, `tests/ingest.php` – local tests for the parser and fragment builder, the matcher, and the multi-page fetch (`php tests/run.php anchor-fragment`, and so on). `tests/semantic.php` tests the second opinion; `node tests/worker.mjs` tests the reference Worker.
* `worker/af-semantic-worker.js` – a reference second-opinion service (a Cloudflare Worker). Not part of the plugin.
* `dist/anchor-fragment.zip` – built copy of the plugin, used to install on a staging site with `wp plugin install <raw URL pinned to a commit> --force`. It is build output: rebuild it from `anchor-fragment/` whenever the plugin changes.

## Status

Prototype. Staging only.

* Fetches Vatican documents on the server, finds numbered paragraphs and builds `#:~:text=` links to them.
* Matches quotations in posts to the paragraph they came from, using a hashed index of 5-word runs.
* 11 single-page documents (encyclicals, Vatican II texts, Dilexi te) plus the Catechism, which is about 370 pages and is fetched from the test page in a resumable job.
* Not yet: canon law (locators like "Can. 1234 §2"), other languages.

## Second opinion (optional, 0.13)

When an author cites a paragraph and the quoted words are not in it, the cause is either a wrong cite or a faithful translation, and comparing runs of words cannot tell which. A second-opinion service compares meaning instead.

The plugin does nothing here until a site owner enters the address and key of such a service (Tools > Anchor Fragment Test > Second opinion) and presses the button. For each citation the words could not confirm (`not_found`, or a typed cite in a translation post) it sends the quotation, the cited document (id, title, address) and the paragraph number, and nothing else. The service answers with signals; the plugin applies its own rule and, when the cited paragraph is the closest in meaning, clearly ahead of the next one, and a model reads it as a faithful translation, marks the edge `semantic`. That status is never `high`, and it is not in the default view. Anything else leaves the edge as it was, with the answer shown beside it on the review screen.

The request and answer format is in the header of `anchor-fragment/includes/class-af-semantic.php`. `worker/af-semantic-worker.js` is a service that follows it: create a Cloudflare Worker, paste the file, add a Workers AI binding named `AI` and a secret named `KEY`, deploy, and enter the Worker's address and the key on the test page. It reads only vatican.va pages, and only documents whose paragraphs are numbered.

