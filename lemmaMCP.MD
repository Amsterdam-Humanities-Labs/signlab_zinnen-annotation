# Lemma MCP Instructions for AI Agents

## Overview
This document provides instructions for AI agents to analyze Dutch sentences and perform lemmatization - converting inflected word forms to their base forms (lemmas).

## API Workflow

### 1. Fetch Sentences
```
GET https://signcollect.nl/zin/getLemmas.php?page=1
```
Returns JSON with paginated sentences (10 per page):
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "zinString": "Dutch sentence text",
      "lemmaList": ["lemma1", "lemma2", ...]
    }
  ],
  "pagination": {
    "current_page": 1,
    "total_pages": 421,
    "per_page": 10,
    "total_sentences": 4209,
    "displayed_range": "1-10",
    "has_next": true,
    "has_previous": false
  }
}
```

**Pagination Parameters:**
- `page`: Page number (default: 1)
- Returns 10 sentences per page
- Check `has_next` to determine if more pages exist

**Processing Multiple Pages:**
```
// Example pagination loop
page = 1
while (true):
    response = GET /getLemmas.php?page={page}
    process(response.data)
    if (!response.pagination.has_next):
        break
    page++
```

### 2. Lemmatization Process

For each sentence:
1. **Tokenize** the `zinString` into individual words
2. **Analyze** each word to determine its lemma
3. **Compare** with existing `lemmaList`
4. **Update** if corrections are needed

### 3. Update Lemmas
```
POST https://signcollect.nl/zin/updateLemmas.php
Content-Type: application/json

{
  "sentenceId": 123,
  "lemmas": ["hebben", "olifant", "lopen", "mooi"]
}
```

## Dutch Lemmatization Rules

### Verbs
- **Conjugated → Infinitive**
  - "heb" → "hebben"
  - "heeft" → "hebben"
  - "liep" → "lopen"
  - "gezien" → "zien"

### Nouns
- **Plural → stays plural**
  - "olifanten" → "olifanten"
  - "huizen" → "huizen"
  - "kinderen" → "kinderen"
- **Diminutive → Base**
  - "huisje" → "huis"
  - "boekje" → "boek"

### Adjectives
- **Inflected → Base**
  - "mooie" → "mooi"
  - "grote" → "groot"
  - "goede" → "goed"

### Common Exceptions
- **Irregular plurals**: "kind" → "kinderen" (not "kinder")
- **Strong verbs**: "ging" → "gaan", "zag" → "zien"
- **Separable verbs**: "opbellen" (treat as single lemma)

## Reliable Sources
When uncertain about lemmatization:
1. **Van Dale** (vandale.nl) - Authoritative Dutch dictionary
2. **ANW** (anw.ivdnt.org) - General Dutch Dictionary
3. **OpenTaal** - Open source Dutch word list

## Example Processing

### Input Sentence:
```json
{
  "id": 456,
  "zinString": "De kinderen hebben twee olifanten gezien",
  "lemmaList": ["De", "kinderen", "hebben", "twee", "olifanten", "gezien"]
}
```

### Analysis:
- "De" → "de" (article, lowercase)
- "kinderen" → "kind" (plural → singular)
- "hebben" → "hebben" (already infinitive)
- "twee" → "twee" (numeral, unchanged)
- "olifanten" → "olifant" (plural → singular)
- "gezien" → "zien" (past participle → infinitive)

### Corrected Output:
```json
{
  "sentenceId": 456,
  "lemmas": ["de", "kind", "hebben", "twee", "olifant", "zien"]
}
```

## Quality Checks

1. **Word Count**: Number of lemmas should match word count in zinString
2. **Part of Speech**: Consider grammatical context for ambiguous words
3. **Compounds**: Dutch compounds should remain as single lemmas
4. **Case Sensitivity**: Generally use lowercase except for proper nouns

## Error Handling

- If a word cannot be lemmatized reliably, keep the original form
- Log uncertain cases for manual review
- Never remove words - empty lemma positions break alignment

## Performance Tips

1. Batch process multiple sentences before updating
2. Cache common lemmatizations
3. Use linguistic patterns for unknown words
4. Implement confidence scoring for automated decisions