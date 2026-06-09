SHELL = /bin/bash

DECISIONS_CSS = https://cdnjs.cloudflare.com/ajax/libs/github-markdown-css/5.5.1/github-markdown.min.css

.PHONY: decisions clean-decisions

## Render DECISIONS.md → DECISIONS.html (GitHub-styled, printable)
decisions: DECISIONS.html

DECISIONS.html: DECISIONS.md
	pandoc \
	  --from=gfm \
	  --to=html5 \
	  --standalone \
	  --metadata title="ESC Registry v2 — Pending Decisions" \
	  --css=$(DECISIONS_CSS) \
	  --variable document-css=false \
	  --include-before-body=<(echo '<div class="markdown-body" style="max-width:860px;margin:40px auto;padding:0 24px 60px">') \
	  --include-after-body=<(echo '</div>') \
	  $< -o $@
	@echo "Generated $@"

clean-decisions:
	rm -f DECISIONS.html
