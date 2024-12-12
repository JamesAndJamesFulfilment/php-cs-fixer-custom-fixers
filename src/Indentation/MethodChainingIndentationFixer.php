<?php

declare(strict_types=1);

namespace JJCustom\Indentation;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

final class MethodChainingIndentationFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
    private const OPEN_CONDITIONS  = ['andClause', 'orClause'];
    private const CLOSE_CONDITIONS = ['endClause'];

    public function getName(): string
    {
        return 'JJCustom/method_chaining_indentation';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Method chaining of Doctrine query conditions nested inside ->andClause() or ->orClause() should be ' .
            'indented an additional level until the closing ->endClause() is reached.',
            [
                new CodeSample(
                    '<?php
$query
    ->andWhere("alias.column = ?", $value)
    ->andClause()
        ->andWhere("alias.column2 = ?", $value2)
        ->orClause()
            ->andWhere("alias.column3 = ?", $value3)
            ->andWhereIn("alias.column4", $value4)
        ->endClause()
    ->endClause();'
                ),
            ]
        );
    }

    /**
     * {@inheritdoc}
     *
     * Replaces PhpCsFixer\Fixer\Whitespace\MethodChainingIndentationFixer.
     */
    public function getPriority(): int
    {
        return 0;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound(Token::getObjectOperatorKinds());
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $line_ending = $this->whitespacesConfig->getLineEnding();

        for ($index = 1, $count = count($tokens); $index < $count; ++$index) {
            if (!$tokens[$index]->isObjectOperator()) {
                continue;
            }

            $end_parenthesis_index = $tokens->getNextTokenOfKind($index, ['(', ';', ',', [T_CLOSE_TAG]]);

            if (null === $end_parenthesis_index || !$tokens[$end_parenthesis_index]->equals('(')) {
                continue;
            }

            if ($this->canBeMovedToNextLine($index, $tokens)) {
                $newline = new Token([T_WHITESPACE, $line_ending]);

                if ($tokens[$index - 1]->isWhitespace()) {
                    $tokens[$index - 1] = $newline;
                } else {
                    $tokens->insertAt($index, $newline);
                    ++$index;
                    ++$end_parenthesis_index;
                }
            }

            $current_indent = $this->getIndentAt($tokens, $index - 1);

            if (null === $current_indent) {
                continue;
            }

            $expected_indent = $this->getExpectedIndentAt($tokens, $index);

            /**
             * Here begins the custom logic for dealing with nested opening and
             * closing conditionals.
             *
             * Tokens themselves are not grouped into lines of code, and we are
             * "anchored" to T_OBJECT_OPERATOR ("->") tokens - all other token
             * types are skipped right at the top of the for loop above. What
             * that means is we must inspect tokens which are ahead of or
             * behind the current T_OBJECT_OPERATOR token by known amounts to
             * have a sense of whether to make changes to the indenting level.
             */

            // the string content of a single level of indentation
            $one_indent = $this->whitespacesConfig->getIndent();

            /**
             * Four tokens ago (`$index - 4`) could be an opening conditional
             * (`andClause` or `orClause`) on the previous line. If that's the
             * case then we must increase the indent at __this token__ by
             * one level.
             */
            if ($this->isOpenCondition($tokens[$index - 4])) {
                $expected_indent .= $one_indent;

            /**
             * It could also be true that the previous line contained an
             * opening conditional, but the line ended with a comment instead
             * of the closing brace. In that situation, the conditional will
             * be located at `$index - 6` instead, but we still need to increase
             * the indent level.
             */
            } elseif ($this->isOpenCondition($tokens[$index - 6])) {
                $expected_indent .= $one_indent;

            /**
             * If this current line is a closing conditional, we __may__
             * have to reduce the indent of this line by one layer.
             */
            } elseif ($this->isCloseCondition($tokens[$index + 1])) {
                /**
                 * There is one known exception where we can't reduce the indent
                 * level - if the nested conditional isn't chained in the usual
                 * manner. For example:
                 *
                 *     $query = $this
                 *         ->createQuery('a')
                 *         ->select('whatever')
                 *         ->orClause();
                 *
                 *     <some other conditional logic goes here>
                 *
                 *     $query
                 *         ->endClause()
                 *         ->execute();
                 *
                 * If two tokens ago (`$index - 2`) is a variable, then we know
                 * __not__ to reduce the indent level for the current line.
                 */
                if (
                    !(
                        isset($tokens[$index - 2])
                        && $this->isVariable($tokens[$index - 2])
                    )
                ) {
                    $expected_indent = mb_substr($expected_indent, 0, - (mb_strlen($one_indent)));
                }
            }

            if ($current_indent !== $expected_indent) {
                $tokens[$index - 1] = new Token([T_WHITESPACE, $line_ending . $expected_indent]);
            }

            $end_parenthesis_index = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $end_parenthesis_index);

            for ($search_index = $index + 1; $search_index < $end_parenthesis_index; ++$search_index) {
                $search_token = $tokens[$search_index];

                if (!$search_token->isWhitespace()) {
                    continue;
                }

                $content = $search_token->getContent();

                if (!Preg::match('/\R/', $content)) {
                    continue;
                }

                $content = Preg::replace(
                    '/(\R)' . $current_indent . '(\h*)$/D',
                    '$1' . $expected_indent . '$2',
                    $content
                );

                $tokens[$search_index] = new Token([$search_token->getId(), $content]);
            }
        }
    }

    /**
     * @param int $index index of the first token on the line to indent
     */
    private function getExpectedIndentAt(Tokens $tokens, int $index): string
    {
        $index  = $tokens->getPrevMeaningfulToken($index);
        $indent = $this->whitespacesConfig->getIndent();

        for ($i = $index; $i >= 0; --$i) {
            if ($tokens[$i]->equals(')')) {
                $i = $tokens->findBlockStart(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $i);
            }

            $current_indent = $this->getIndentAt($tokens, $i);
            if (null === $current_indent) {
                continue;
            }

            if ($this->currentLineRequiresExtraIndentLevel($tokens, $i, $index)) {
                return $current_indent . $indent;
            }

            return $current_indent;
        }

        return $indent;
    }

    /**
     * @param int $index position of the object operator token ("->" or "?->")
     */
    private function canBeMovedToNextLine(int $index, Tokens $tokens): bool
    {
        $prev_meaningful    = $tokens->getPrevMeaningfulToken($index);
        $has_comment_before = false;

        for ($i = $index - 1; $i > $prev_meaningful; --$i) {
            if ($tokens[$i]->isComment()) {
                $has_comment_before = true;

                continue;
            }

            if ($tokens[$i]->isWhitespace() && Preg::match('/\R/', $tokens[$i]->getContent())) {
                return $has_comment_before;
            }
        }

        return false;
    }

    /**
     * @param int $index index of the indentation token
     */
    private function getIndentAt(Tokens $tokens, int $index): ?string
    {
        if (Preg::match('/\R{1}(\h*)$/', $this->getIndentContentAt($tokens, $index), $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getIndentContentAt(Tokens $tokens, int $index): string
    {
        if (!$tokens[$index]->isGivenKind([T_WHITESPACE, T_INLINE_HTML])) {
            return '';
        }

        $content = $tokens[$index]->getContent();

        if ($tokens[$index]->isWhitespace() && $tokens[$index - 1]->isGivenKind(T_OPEN_TAG)) {
            $content = $tokens[$index - 1]->getContent() . $content;
        }

        if (Preg::match('/\R/', $content)) {
            return $content;
        }

        return '';
    }

    /**
     * @param int $start index of first meaningful token on previous line
     * @param int $end   index of last token on previous line
     */
    private function currentLineRequiresExtraIndentLevel(Tokens $tokens, int $start, int $end): bool
    {
        $first_meaningful = $tokens->getNextMeaningfulToken($start);

        if ($tokens[$first_meaningful]->isObjectOperator()) {
            $third_meaningful = $tokens->getNextMeaningfulToken($tokens->getNextMeaningfulToken($first_meaningful));

            return
                (
                    $tokens[$third_meaningful]->equals('(')
                    && $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $third_meaningful) > $end
                );
        }

        return
            !$tokens[$end]->equals(')')
            || $tokens->findBlockStart(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $end) >= $start;
    }

    private function isOpenCondition(Token $token): bool
    {
        return $token->getName() == 'T_STRING' && in_array($token->getContent(), self::OPEN_CONDITIONS);
    }

    private function isCloseCondition(Token $token): bool
    {
        return $token->getName() == 'T_STRING' && in_array($token->getContent(), self::CLOSE_CONDITIONS);
    }

    private function isVariable(Token $token): bool
    {
        return $token->getName() == 'T_VARIABLE';
    }
}
