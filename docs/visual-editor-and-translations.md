# Visual Editor And Translations

This project installs the community `friendsoftypo3/visual-editor` extension
and configures Camino for inline page editing. It is not part of the official
Camino distribution.

## What Editors Get

- **Content > Editor** renders the Camino page inside the TYPO3 backend.
- Text content can be selected and edited in its real frontend context.
- Spotlight mode identifies editable areas.
- Single-language and side-by-side language modes are available.
- `/visual-editor` contains a short captioned demonstration of the workflow.

The video is deliberately committed to
`public/fileadmin/camino/visual-editor-demo.mp4`. It is about 1.3 MB, uses H.264
at 1280 x 720, has no audio, and includes English WebVTT captions. It therefore
works without an external video account or a runtime upload.

## Included Languages

| ID | Language | Base path | Locale | Fallback |
|---:|---|---|---|---|
| 0 | English | `/` | `en_US.UTF-8` | Default |
| 1 | German | `/de/` | `de_DE.UTF-8` | Strict |
| 2 | Spanish | `/es/` | `es_ES.UTF-8` | Strict |
| 3 | Simplified Chinese | `/zh/` | `zh_CN.UTF-8` | Strict |
| 4 | Hungarian | `/hu/` | `hu_HU.UTF-8` | Strict |

Strict mode is intentional: TYPO3 does not display the English source record
when a translated page or content element is missing. Editors can immediately
see incomplete localization instead of publishing a mixed-language page.

The seed command creates connected translations for nine pages and the core
content needed by the demo in every language. It does not machine-translate
future editor content.

## Initial Setup

New empty databases are configured automatically by the container bootstrap.
For an existing TYPO3 database, run:

```bash
vendor/bin/typo3 extension:setup --no-interaction
vendor/bin/typo3 webconsulting:camino-demo:setup --flush-caches
```

For local DDEV development, prefix both commands with `ddev exec`.

The setup command is idempotent. It creates or updates the Visual Editor page,
its custom content element, all site-language page overlays, and connected
content overlays without duplicating records.

## Editing A Translation

1. Sign in at `/typo3/`.
2. Open **Content > Editor**.
3. Select the page in the page tree.
4. Choose a language in the top-right language menu.
5. Select **Multi language** to compare English and the translation side by
   side, or keep **Single language** for more space.
6. Select an editable text area, make the change, and save.

Use a real PostgreSQL or MySQL-compatible database before editorial work. The
one-click SQLite database is copied into each Vercel instance and is not a
durable source of content or translations.

## Implementation Notes

Camino already uses TYPO3's page-view rendering, content-area rendering, text
rendering, and record transformation expected by the Visual Editor. The local
`webconsulting/typo3-camino-demo` package adds only the demonstration content
element, CSS, translations, and repeatable setup command.

The frontend video is repository media, while new editor uploads should use the
`vercel_blob` or `vercel_s3` FAL storage. Do not replace the committed demo
video from the one-click SQLite environment and expect that database reference
to remain stable across instances.
