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

## 0.14.1

A cite that starts the sentence right after a quotation and is its subject ("... Catechism 1735 concerns freedom and culpability") is now a mention of that paragraph, not the quotation's source. Before, it was attached to the quotation just ended, so a footnote quoted from a pope's text (which merely names the Catechism paragraph) showed up as "cited Catechism 1735, not found". Cites in brackets or ending the sentence after the quotation are unaffected.

## 0.14.2

- **Earlier wordings of revised paragraphs.** The Catechism's no. 2267 (the death penalty) was rewritten in 2018, and the vatican.va text is the new one, so posts quoting the 1997 wording showed up as "not found". The plugin now ships `data/catechism-editions.json`: hashed five-word runs (never text) of the old no. 2267, merged into the Catechism's index when matching. A quotation of the old wording matches paragraph 2267 as usual. The file can take other paragraphs and other revised documents.
- **Context on the review cards.** Each card on Tools > Citations to fix now shows a few words either side of the quotation, so you can see what the cite was read from. It appears once the post is matched again.

## 0.15.5

A bare number with no document still makes the guess that goes by the words alone, as before 0.14.5; the second guess is added beside it, not in its place.

## 0.15.4

The second guess for a bare number is kept until another work is named, instead of being cleared by a quotation whose lead names none.

## 0.15.3

A bare number after a quotation ("(1)", "#12") is tried against two documents when there are two guesses: the one cited last, and the one named in the text before the last quotation that had no cite of its own. The quotation's words decide which counts. In 0.14.5 the second replaced the first, which lost "(1)" and "(2)" in post 16860 (an earlier Dignitatis humanae cite, overwritten by a passing mention) and "paragraph 230" in post 9914. 0.15.2's narrower rule lost the 13 Fratelli Tutti cites 0.14.5 had gained in post 11188, so it is replaced.

## 0.15.2

The document carried on to a bare number after a quotation is taken from the work named just before the quotation (the last 90 characters), not from one named further back. In 0.14.5 a document named further back replaced the one an earlier cite had set, and "(1)" and "(2)" in post 16860 stopped agreeing with Dignitatis humanae.

## 0.15.1

Snapshots tell edges apart by their basis as well, so edges of one quotation that share a document and paragraph are no longer merged; an edge that changes basis is shown as one move.

## 0.15.0

Snapshots of the citation graph, for checking what a change to the parser's rules moved. There is no screen for it: `POST /graph/snapshot?label=` saves every edge's status (no text; the six newest are kept), and `GET /graph/diff?a=<label>&b=current&transition=` lists the edges that moved with the words around each quotation. Both are for administrators and editors. See `docs/parser-log.md` for the record of what each rule change moved.

## 0.14.5

Found by comparing the whole graph under 0.14.2 and 0.14.3 (every difference: 5 cards):

- `This teaching is found in the now twice-revised paragraph 2267 of the Catechism`: a sentence that says where the quotation is found names its source.
- A bare `(#12)` after a quotation takes its document from the work named just before the earlier quotation when that quotation had no cite of its own (Dignitatis humanae, in post 3815), where it used to fall back to the Catechism.
- The other differences were correct: six "not found" cards became mentions, and two false conflicts (a cite given to the wrong quotation, and a Compendium number) went away.

## 0.14.4

Two cites that 0.14.3 wrongly turned into mentions:

- `... Never again war!” (Fratelli Tutti 258)`: a bracketed cite right after the closing mark that ends the quotation is the quotation's cite, not a cite inside it.
- `Catechism 1735 concerns freedom. It says, “...”`: when the sentence introducing the quotation is only a pronoun and a verb ("It says,"), the sentence before it counts as the introduction.

## 0.14.3

Reading what follows a quotation more carefully. A cite is no longer taken from text after the quotation when that text is the author's own sentence rather than a cite:

- "Citing Amoris Laetitia 292, he added ..." and "It's also a rejection of Lumen Gentium 25 ..." (a capitalised word that does not begin a document's name, a code, or a "cf./see").
- "(Footnote 39 says, ...)" and "(Note that ... paragraph 2296 of the Catechism ...)" (a bracket that is a sentence).
- "The revised formulation of Catechism #2267, states:" (a sentence that introduces the next quotation).
- A closing quotation mark with no opening one before it: the cite sits inside the quotation, which nested quotation marks ended early.

A bracket or a dash and a name still attribute, as before. Also, "(Compendium of the CCC 176)" is no longer read as Catechism 176, since the Compendium has its own numbers. The text after the quotation still tells which document is being discussed.
