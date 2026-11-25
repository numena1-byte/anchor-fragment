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

The plugin does nothing here until a site owner enters the address and key of such a service (Tools > Anchor Fragment Test > Second opinion) and presses the button. For each citation the words could not confirm (`not_found`, or a typed cite in a translation post) it sends the quotation, the cited document (id, title, address) and the paragraph number, and nothing else. The service answers with signals; the plugin applies its own rule and, when a model reads the cited paragraph as a faithful translation and the paragraph is close enough in meaning (0.13.1: at least 50% similar, at any rank, because short quotations rank badly), marks the edge `semantic`. A very close paragraph that a model nonetheless sees as different is left as unclear, since it may be another English translation. That status is never `high`, and it is not in the default view. Anything else leaves the edge as it was, with the answer shown beside it on the review screen.

The request and answer format is in the header of `anchor-fragment/includes/class-af-semantic.php`. `worker/af-semantic-worker.js` is a service that follows it: create a Cloudflare Worker, paste the file, add a Workers AI binding named `AI` and a secret named `KEY`, deploy, and enter the Worker's address and the key on the test page. It reads only vatican.va pages, and only documents whose paragraphs are numbered. For the Catechism, which spans many pages, the plugin reads the cited paragraph itself and sends it with the quotation (`passage`); A PDF (Amoris laetitia) is handled the same way, using the paragraph text an author added from it on this site (its HTML page on vatican.va is only a cover); paragraphs with no text added are not asked. Quotations whose words were found in a document the registry does not hold, and very short ones, are not sent.

## 0.13.1

Cites of a work the registry does not hold ("(EV 56)", "HV no. 20") are no longer attached to a registered document named in the same sentence. Donum veritatis, Evangelium vitae and Humanae vitae are registered; `DV`, shared by Dei verbum and Donum veritatis, is settled by the words, or by the document named just before. The second-opinion rule is as above, answers made under the 0.13.0 rule are read again under the new one from the signals they kept, and the Worker takes a `passage` for multi-page documents. Redeploy `worker/af-semantic-worker.js` to use these.


## 0.13.2

Humanae vitae, Evangelium vitae and Donum veritatis print a signature line and the notes after the last numbered paragraph. The parser read all of that as part of the last paragraph (HV 31 came to nearly 7,000 characters), so a quotation matching a footnote was pinned on it. A document can now name where its last paragraph ends (`parse.end_at` in the registry, a regular expression); the three documents end at "Given in/at ..." or "NOTES". Documents are fetched again when their parse options change, so the three need "Fetch" once on the test page.

## 0.14.0

A new screen, Tools > Citations to fix, lists the quotations whose cite and words disagree (or whose cite could not be found, or whose words fit several paragraphs). One card per quotation, grouped by post: what you cited, where the words are, a plain-words diagnosis (next to the cited paragraph, same document, another document), links to both paragraphs on vatican.va, a side-by-side comparison of the two paragraphs with the quoted words highlighted, and a link to edit the post. Nothing in a post is changed. For each card you can record "my cite is right", "the cite needs fixing" or "ignore"; the card leaves the to-do list and the choice can be undone. A decision survives matching the post again. Filters: kind, to do / decided, document, words in the title. "Download these as CSV" saves the list.
