# Parser change log

Each time a rule in the analyzer changes, the whole citation graph is saved before and after (a snapshot, `/graph/snapshot`) and every edge that moved is read (`/graph/diff`). This file records what each change moved and how many of the moves were mistakes, so we can see when new rules stop paying for themselves.

How to read a row: "moved" is the number of edges whose status changed (or that appeared or went). "Wrong" is how many of those, once read, were a regression. "Fixed" is the number of cards the change was meant to repair.

| Version | Rule changed | Moved | Wrong | Notes |
|---|---|---|---|---|
| 0.14.2 → 0.14.3 | Text after a quotation that is the author's own sentence is not a cite; Compendium numbers are not CCC cites | 11 | 2 | 6 not_found became mention (intended), 3 correct removals, 2 regressions: a sentence saying where the quotation is found ("This teaching is found in … 2267"), and a bare "(#12)" that lost its document |
| 0.14.3 → 0.14.4 | A cite right after the quotation's closing mark; a cite before an "It says," intro | 0 | 0 | Counts did not move: the graph already credited a typed cite that agrees with the words. A cosmetic fix on the review card |
| 0.14.3 → 0.14.5 | The "found in" sentence; the document named before the quotation carries to a bare number | 27 | not yet read | high +14, low −11, medium −2, conflict −2. The 14 new highs have not been read yet |

## When to stop

A proposal, to be revised with more rows: stop adding rules when two changes in a row each fix fewer than 5 cards, or when a change causes a regression among the edges it moves. From then on, what is left goes to the review page and the LLM judge.
