import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';

import { useClipboard } from '@/hooks/use-clipboard';
import { useTranslate } from '@/hooks/use-translate';
import type { CodeToken } from '@/lib/highlight';
import { highlight, resolveLanguage } from '@/lib/highlight';
import { cn } from '@/lib/utils';

/**
 * A fenced code block in a message.
 *
 * Highlighting arrives after the block does, and on purpose. Shiki and its
 * grammars are fetched on demand — a workspace that never posts code never
 * downloads any of it — so the first paint is the plain monospace block and the
 * colours land a moment later. The alternative is a message that shows nothing
 * while a grammar downloads, which is a worse trade in a chat than a beat of
 * uncoloured text.
 */
export function CodeBlock({
    code,
    language,
    className,
}: {
    code: string;
    /** The label the author typed after the fence, or null for a bare one. */
    language: string | null;
    className?: string;
}) {
    const { t } = useTranslate();
    const [tokens, setTokens] = useState<CodeToken[][] | null>(null);
    const [copied, copy] = useClipboard();

    /*
     * Whatever was last highlighted. Compared during render rather than cleared
     * in the effect: an effect runs after the paint, so editing a message would
     * show the previous code's colours over the new text for a frame first.
     * Adjusting state during render is React's own answer for state that has to
     * follow a prop.
     */
    const [highlighted, setHighlighted] = useState({ code, language });

    if (highlighted.code !== code || highlighted.language !== language) {
        setHighlighted({ code, language });
        setTokens(null);
    }

    useEffect(() => {
        // Nothing to wait for when we do not carry the grammar: the plain block
        // below is already the final answer, and the reset above has run.
        if (resolveLanguage(language) === null) {
            return;
        }

        let current = true;

        highlight(code, language)
            .then((result) => {
                /*
                 * A message can be edited while its grammar is still loading, and
                 * a promise that resolves after that would paint the old code
                 * over the new. The flag is what makes the late arrival harmless.
                 */
                if (current) {
                    setTokens(result);
                }
            })
            .catch(() => {
                // A grammar that fails to load is not worth a broken message:
                // the block stays plain, which is what it already looked like.
                if (current) {
                    setTokens(null);
                }
            });

        return () => {
            current = false;
        };
    }, [code, language]);

    return (
        <div
            className={cn(
                'group/code relative my-1 overflow-hidden rounded-md border bg-muted/40',
                className,
            )}
        >
            <div className="flex items-center gap-2 border-b bg-muted/60 px-2 py-1">
                <span className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                    {language ?? 'code'}
                </span>

                <button
                    type="button"
                    // Always in the DOM rather than mounted on hover: a control
                    // that only exists while the pointer is over it is one a
                    // keyboard can never reach.
                    onClick={() => void copy(code)}
                    className="ml-auto flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground opacity-0 transition-opacity group-hover/code:opacity-100 hover:bg-background focus-visible:opacity-100 focus-visible:ring-2 focus-visible:outline-none"
                >
                    {copied === code ? (
                        <>
                            <Check className="size-3" />
                            {t('chat_ui.code.copied')}
                        </>
                    ) : (
                        <>
                            <Copy className="size-3" />
                            {t('chat_ui.code.copy')}
                        </>
                    )}
                </button>
            </div>

            {/*
                whitespace-pre, not pre-wrap: code is the one thing in a message
                that must not be re-wrapped, because where a line breaks is
                sometimes the difference between two statements and one. It
                scrolls sideways instead — the surrounding message body sets
                pre-wrap, so this has to say so for itself.
            */}
            <pre className="pd-code overflow-x-auto p-3 text-[0.8rem] leading-relaxed whitespace-pre">
                <code>
                    {tokens === null
                        ? code
                        : tokens.map((line, index) => (
                              <span key={index} className="block">
                                  {line.map((token, position) => (
                                      <span key={position} style={token.style}>
                                          {token.content}
                                      </span>
                                  ))}
                                  {/*
                                      An empty line has no tokens and would
                                      collapse to nothing. The newline keeps the
                                      blank line somebody deliberately left in
                                      their code.
                                  */}
                                  {line.length === 0 && '\n'}
                              </span>
                          ))}
                </code>
            </pre>
        </div>
    );
}
