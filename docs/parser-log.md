# Parser change log

Each time a rule in the analyzer changes, the whole citation graph is saved before and after (a snapshot, `/graph/snapshot`) and every edge that moved is read (`/graph/diff`). This file records what each change moved and how many of the moves were mistakes, so we can see when new rules stop paying for themselves.

How to read a row: "moved" is the number of edges whose status changed (or that appeared or went). "Wrong" is how many of those, once read, were a regression. "Fixed" is the number of cards the change was meant to repair.

| Version | Rule changed | Moved | Wrong | Notes |
|---|---|---|---|---|
| 0.14.2 → 0.14.3 | Text after a quotation that is the author's own sentence is not a cite; Compendium numbers are not CCC cites | 11 | 2 | 6 not_found became mention (intended), 3 correct removals, 2 regressions: a sentence saying where the quotation is found ("This teaching is found in … 2267"), and a bare "(#12)" that lost its document |
| 0.14.3 → 0.14.4 | A cite right after the quotation's closing mark; a cite before an "It says," intro | 0 | 0 | Counts did not move: the graph already credited a typed cite that agrees with the words. A cosmetic fix on the review card |
| 0.14.3 → 0.14.5 | The "found in" sentence; the document named before the quotation replaces the one carried to a bare number | 19 | 2 (found only by reading) | high +14, low −11, medium −2, conflict −2. 13 correct gains (Fratelli Tutti, post 11188), but the replaced carry broke "(1)" and "(2)" in post 16860 (high to low), and a post 9914 guess was lost between 0.14.4 and 0.14.5 |
| 0.14.5 → 0.15.2 | Only take the document named within 90 characters of the quotation | 4 | 1 | Lost the 13 correct gains and the 3815 fix, and 9914 still regressed. Dropped |
| 0.15.2 → 0.15.3 | Keep both guesses (the last firm cite's document and the one named before) | 5 | 0 | Regressions gone, but the 13 gains were missing too: the second guess was cleared whenever a quotation's lead named no work |
| 0.15.3 → 0.15.4 | Keep the second guess until another work is named | 19 | 2 | The gains returned, and the 16860 regression with them: the guess with no document at all ("null 1") that had credited "(1)" and "(2)" was gone |
| 0.15.4 → 0.15.5 | The second guess goes beside the guess by words alone | 17 | 0 | Against 0.14.3: 13 low to high, 2 medium to high, 1 conflict to high, 1 false conflict gone. Table: high 1096, medium 598, low 522, conflict 56 |

What the rows say so far: one rule (a document carried to a bare number) took five versions to get right, and four of the five versions would have shipped a regression if the graph had not been compared. The comparison found every one of them; reading the cards alone did not.

## When to stop

A proposal, to be revised with more rows: stop adding rules when two changes in a row each fix fewer than 5 cards, or when a change causes a regression among the edges it moves. From then on, what is left goes to the review page and the LLM judge.
