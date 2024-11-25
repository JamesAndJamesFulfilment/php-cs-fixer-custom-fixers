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
             * the string content of a single level of indentation, based on the
             * current file contents
             */
            $one_indent = $this->whitespacesConfig->getIndent();

            /**
             * If we know we are dealing with either an opening conditional
             * (`andClause()` or `orClause()`), or a closing conditional
             * (`endClause()`), there should be exactly five tokens which make
             * up one that line of code:
             *
             *     - a newline character and at least one character of whitespace
             *       indent - this equates to `$index - 1`; and
             *      - the T_OBJECT_OPERATOR itself (`$index`); and
             *      - the string method name (`$index + 1`); and
             *      - an opening parenthesis (`$index + 2`); and
             *      - a closing parenthesis (`$index + 3`).
             *
             * As each line of code is processed, the state of the previous line
             * dictates the starting point for the current line. If we increase
             * (or decrease) the amount of indent on the current line, that
             * change will stay in effect for this and all __following__ lines
             * until something else modifies it again.
             *
             * We cannot modify any following or previous lines, but we can look
             * around at previous and following lines to make decisions about
             * what to do on the current line. In this case, `$index - 4` should
             * be the string method name from the __previous__ line.
             *
             * If that previous string method name is an opening conditional,
             * then we know that __this__ line must be modified to have an
             * additional layer of indenting.
             */
            if ($this->isOpenCondition($tokens[$index - 4])) {
                $expected_indent .= $one_indent;
            } elseif (
                /**
                 * However, if this current line is a closing conditional, we
                 * will probably have to reduce the indent of this line by one
                 * layer. The second test ending `... == T_VARIABLE` in this
                 * conditional is the one known exception to this need to reduce
                 * indentation, and is best explained with the single example
                 * found so far:
                 *
                 *      $query = $this
                 *          ->createQuery('a')
                 *          ->select('whatever')
                 *          ->orClause();
                 *
                 * <some conditional logic goes here>
                 *
                 *     $query
                 *         ->endClause()
                 *         ->execute();
                 *
                 * If we don't handle that the preceeding element is the `$query`
                 * variable, then the `->endClause()` would have its indentation
                 * reduced incorrectly to be flush with the `$query` indent.
                 */
                $this->isCloseCondition($tokens[$index + 1])
                && !(isset($tokens[$index - 2]) && $tokens[$index - 2]->getName() == 'T_VARIABLE')
            ) {
                $expected_indent = mb_substr($expected_indent, 0, - (mb_strlen($one_indent)));
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
}
