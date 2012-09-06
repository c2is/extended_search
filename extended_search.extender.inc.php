<?php
class SearchQueryRegexp extends SearchQuery
{
    protected function parseSearchExpressionRegexp() {
        // Matchs words optionally prefixed by a dash. A word in this case is
            // something between two spaces, optionally quoted.
            preg_match_all('/ (-?)("[^"]+"|[^" ]+)/i', ' ' .  $this->searchExpression , $keywords, PREG_SET_ORDER);

            if (count($keywords) ==  0) {
              return;
            }

            // Classify tokens.
            $or = FALSE;
            $warning = '';
            $limit_combinations = variable_get('search_and_or_limit', 7);
            // The first search expression does not count as AND.
            $and_count = -1;
            $or_count = 0;
            foreach ($keywords as $match) {
              if ($or_count && $and_count + $or_count >= $limit_combinations) {
                // Ignore all further search expressions to prevent Denial-of-Service
                // attacks using a high number of AND/OR combinations.
                $this->expressionsIgnored = TRUE;
                break;
              }
              $phrase = FALSE;
              // Strip off phrase quotes.
              if ($match[2]{0} == '"') {
                $match[2] = substr($match[2], 1, -1);
                $phrase = TRUE;
                $this->simple = FALSE;
              }
              // Simplify keyword according to indexing rules and external
              // preprocessors. Use same process as during search indexing, so it
              // will match search index.
              $words = search_simplify($match[2]);
              // Re-explode in case simplification added more words, except when
              // matching a phrase.
              $words = $phrase ? array($words) : preg_split('/ /', $words, -1, PREG_SPLIT_NO_EMPTY);
              // Negative matches.
              if ($match[1] == '-') {
                $this->keys['negative'] = array_merge($this->keys['negative'], $words);
              }
              // OR operator: instead of a single keyword, we store an array of all
              // OR'd keywords.
              elseif ($match[2] == 'OR' && count($this->keys['positive'])) {
                $last = array_pop($this->keys['positive']);
                // Starting a new OR?
                if (!is_array($last)) {
                  $last = array($last);
                }
                $this->keys['positive'][] = $last;
                $or = TRUE;
                $or_count++;
                continue;
              }
              // AND operator: implied, so just ignore it.
              elseif ($match[2] == 'AND' || $match[2] == 'and') {
                $warning = $match[2];
                continue;
              }

              // Plain keyword.
              else {
                if ($match[2] == 'or') {
                  $warning = $match[2];
                }
                if ($or) {
                  // Add to last element (which is an array).
                  $this->keys['positive'][count($this->keys['positive']) - 1] = array_merge($this->keys['positive'][count($this->keys['positive']) - 1], $words);
                }
                else {
                  $this->keys['positive'] = array_merge($this->keys['positive'], $words);
                  $and_count++;
                }
              }
              $or = FALSE;
            }

            // Convert keywords into SQL statements.
            $this->conditions = db_and();
            $simple_and = FALSE;
            $simple_or = FALSE;
            // Positive matches.
            foreach ($this->keys['positive'] as $key) {
              // Group of ORed terms.
              if (is_array($key) && count($key)) {
                $simple_or = TRUE;
                $any = FALSE;
                $queryor = db_or();
                foreach ($key as $or) {
                  list($num_new_scores) = $this->parseWord($or);
                  $any |= $num_new_scores;
                  $queryor->condition('d.data', $or, 'REGEXP');
                }
                if (count($queryor)) {
                  $this->conditions->condition($queryor);
                  // A group of OR keywords only needs to match once.
                  $this->matches += ($any > 0);
                }
              }
              // Single ANDed term.
              else {
                $simple_and = TRUE;
                list($num_new_scores, $num_valid_words) = $this->parseWord($key);
                $this->conditions->condition('d.data', $key, 'REGEXP');
                if (!$num_valid_words) {
                  $this->simple = FALSE;
                }
                // Each AND keyword needs to match at least once.
                $this->matches += $num_new_scores;
              }
            }
            if ($simple_and && $simple_or) {
              $this->simple = FALSE;
            }
            // Negative matches.
            foreach ($this->keys['negative'] as $key) {
              $this->conditions->condition('d.data', "% $key %", 'NOT LIKE');
              $this->simple = FALSE;
            }

            if ($warning == 'or') {
              drupal_set_message(t('Search for either of the two terms with uppercase <strong>OR</strong>. For example, <strong>cats OR dogs</strong>.'));
            }
      }

    public function executeFirstPassRegexp() {
        $this->parseSearchExpressionRegexp();

            if (count($this->words) == 0) {
              form_set_error('keys', format_plural(variable_get('minimum_word_size', 3), 'You must include at least one positive keyword with 1 character or more.', 'You must include at least one positive keyword with @count characters or more.'));
              return FALSE;
            }
            if ($this->expressionsIgnored) {
              drupal_set_message(t('Your search used too many AND/OR expressions. Only the first @count terms were included in this search.', array('@count' => variable_get('search_and_or_limit', 7))), 'warning');
            }
            $this->executedFirstPass = TRUE;

            if (!empty($this->words)) {
              $or = db_or();
              foreach ($this->words as $word) {
                $or->condition('i.word', $word,"REGEXP");
              }
              $this->condition($or);
            }
            // Build query for keyword normalization.
            $this->join('search_total', 't', 'i.word = t.word');
            $this
              ->condition('i.type', $this->type)
              ->groupBy('i.type')
              ->groupBy('i.sid')
              ->having('COUNT(*) >= :matches', array(':matches' => $this->matches));

            // Clone the query object to do the firstPass query;
            $first = clone $this->query;

            // For complex search queries, add the LIKE conditions to the first pass query.
            if (!$this->simple) {
              $first->join('search_dataset', 'd', 'i.sid = d.sid AND i.type = d.type');
              $first->condition($this->conditions);
            }

            // Calculate maximum keyword relevance, to normalize it.
            $first->addExpression('SUM(i.score * t.count)', 'calculated_score');
            $this->normalize = $first
              ->range(0, 1)
              ->orderBy('calculated_score', 'DESC')
              ->execute()
              ->fetchField();

            if ($this->normalize) {
              return TRUE;
            }
            return FALSE;
      }
    protected function testMe(){
        return "yo";
    }
}