<?php

namespace Taoshan\QuillMarkdown\Tests;

use PHPUnit\Framework\TestCase;
use Taoshan\QuillMarkdown\Config\Options;
use Taoshan\QuillMarkdown\Delta\Delta;
use Taoshan\QuillMarkdown\QuillMarkdown;

class RealWorldUseCasesTest extends TestCase
{
    public function testUseCase1TechnicalReleaseNotesMarkdownToDeltaAndBack(): void
    {
        $qm = new QuillMarkdown(new Options(enableDividerBlot: true));

        $markdown = <<<'MD'
# Release v2.0.0 — Motore Bidirezionale

Questo aggiornamento introduce il supporto **completo *bidirezionale*** tra [Markdown GFM](https://github.github.com/gfm/) e [QuillJS](https://quilljs.com/docs/delta_(format)).

> Nota: i payload con carattere `"0"` e gli identificatori `snake_case_name` restano intatti.

## Checklist di migrazione

1. Aggiornare il pacchetto Composer
  1. Verificare i requisiti PHP `>= 8.1`
  2. Eseguire `./vendor/bin/phpunit`
2. Controllare lo stato dei task:
- [x] Parser Markdown -> Delta con escaping `\*sicuro\*`
- [ ] Renderer Delta -> Markdown per tabella riepilogativa

---

## Matrice di compatibilità

| Funzionalità | Stato | Note |
| --- | :---: | ---: |
| Tabelle GFM | **Attivo** | Supporta `a | b` e a \| b |
| Code block | **Attivo** | Valore `0` preservato |

```php
$qm = new QuillMarkdown();
$delta = $qm->toDelta($markdown);
```

Per dettagli vedi la documentazione[^1] sulle specifiche HTTP/2.

*[HTTP/2]: Hypertext Transfer Protocol version 2
[^1]: Verifica completata con analisi statica **PHPStan** livello 8.
MD;

        $delta = $qm->toDelta($markdown);
        $roundTrippedMarkdown = $qm->toMarkdown($delta);
        $reDelta = $qm->toDelta($roundTrippedMarkdown);

        $this->assertSame($delta->compact()->toArray(), $reDelta->compact()->toArray());
        $this->assertStringContainsString('## Matrice di compatibilità', $roundTrippedMarkdown);
        $this->assertStringContainsString('*[HTTP/2]: Hypertext Transfer Protocol version 2', $roundTrippedMarkdown);
        $this->assertStringContainsString('[^1]: Verifica completata con analisi statica **PHPStan** livello 8.', $roundTrippedMarkdown);
    }

    public function testUseCase2CompactedQuillFrontendJsonToMarkdown(): void
    {
        $qm = (new QuillMarkdown(new Options(enableDividerBlot: true)))
            ->registerEmbed(
                blotName: 'mention',
                toMarkdown: static fn(mixed $v): string => is_array($v) ? '@' . ($v['value'] ?? '') : '',
            )
            ->registerEmbed(
                blotName: 'formula',
                toMarkdown: static fn(mixed $v): string => is_string($v) ? '$' . $v . '$' : '',
            );

        $quillJsonFromBrowser = json_encode([
            'ops' => [
                ['insert' => 'Verbale Sprint #42'],
                ['insert' => "\n", 'attributes' => ['header' => 1]],
                ['insert' => 'Partecipanti: '],
                ['insert' => ['mention' => ['id' => 7, 'value' => 'marco.rossi']]],
                ['insert' => ' e '],
                ['insert' => ['mention' => ['id' => 12, 'value' => 'elena.bianchi']]],
                ['insert' => "\n\nObiettivi principali:\nRevisare la formula "],
                ['insert' => ['formula' => '\\sum_{i=1}^n x_i']],
                ['insert' => ' con'],
                ['insert' => ' attenzione ', 'attributes' => ['bold' => true]],
                ['insert' => 'alle '],
                ['insert' => 'API ', 'attributes' => ['bold' => true]],
                ['insert' => 'pubbliche', 'attributes' => ['bold' => true, 'italic' => true]],
                ['insert' => ' nel'],
                ['insert' => ' repository', 'attributes' => ['link' => 'https://example.com/repo']],
                ['insert' => '.'],
                ['insert' => "\n", 'attributes' => ['blockquote' => true]],
                ['insert' => 'Task 1'],
                ['insert' => "\n", 'attributes' => ['list' => 'checked']],
                ['insert' => 'Task 2'],
                ['insert' => "\n", 'attributes' => ['list' => 'unchecked']],
                ['insert' => ['divider' => true]],
                ['insert' => 'Endpoint'],
                ['insert' => "\n", 'attributes' => ['table' => 'row-x9']],
                ['insert' => 'Latenza'],
                ['insert' => "\n", 'attributes' => ['table' => 'row-x9', 'align' => 'right']],
                ['insert' => '/v2/convert'],
                ['insert' => "\n", 'attributes' => ['table' => 'row-y2']],
                ['insert' => '12 ms'],
                ['insert' => "\n", 'attributes' => ['table' => 'row-y2', 'align' => 'right']],
                ['insert' => "const res = await fetch('/v2/convert');"],
                ['insert' => "\n", 'attributes' => ['code-block' => 'typescript']],
            ],
        ], JSON_THROW_ON_ERROR);

        $markdown = $qm->toMarkdown($quillJsonFromBrowser);

        $expectedMarkdown = <<<'MD'
# Verbale Sprint #42
Partecipanti: @marco.rossi e @elena.bianchi

Obiettivi principali:
> Revisare la formula $\sum_{i=1}^n x_i$ con **attenzione** alle **API *pubbliche*** nel [repository](https://example.com/repo).
- [x] Task 1
- [ ] Task 2
---
| Endpoint | Latenza |
| --- | ---: |
| /v2/convert | 12 ms |
```typescript
const res = await fetch('/v2/convert');
```
MD;

        $this->assertSame($expectedMarkdown, $markdown);
    }

    public function testUseCase3AcademicAndApiSpecDocument(): void
    {
        $qm = new QuillMarkdown(new Options(preserveUnderlineHtml: true));

        $markdown = <<<'MD'
# Specifica Chimica e Complessità

La reazione usa H~2~O e ha complessità O(n^2^) con costo ~10 o ~20 ms, mentre 5 * 3 * 2 = 30.

Stato: ==critico== e <u>conforme</u> alla variabile `user_id_token` (foo_bar_baz).

Quill Delta
: Formato JSON basato su operazioni `insert`[^spec].

*[JSON]: JavaScript Object Notation
[^spec]: Consulta la documentazione ufficiale[^2] se necessario.
[^2]: Standard RFC 8259.
MD;

        $delta = $qm->toDelta($markdown);
        $backToMarkdown = $qm->toMarkdown($delta);
        $reDelta = $qm->toDelta($backToMarkdown);

        $this->assertSame($delta->compact()->toArray(), $reDelta->compact()->toArray());
        $this->assertStringContainsString('H~2~O', $backToMarkdown);
        $this->assertStringContainsString('O(n^2^)', $backToMarkdown);
        $this->assertStringContainsString('5 * 3 * 2 = 30', $backToMarkdown);
        $this->assertStringContainsString('==critico==', $backToMarkdown);
        $this->assertStringContainsString('<u>conforme</u>', $backToMarkdown);
        $this->assertStringContainsString(': Formato JSON basato su operazioni `insert`[^spec].', $backToMarkdown);
    }
}
