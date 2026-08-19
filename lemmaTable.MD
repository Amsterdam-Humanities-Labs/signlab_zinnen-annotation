# Lemma Table Documentation

## Overview
The lemmaTable is a normalized database table that stores unique lemmas (base forms of words) used across all sentences in the ZIN system. This approach replaces storing lemma strings directly in the sentences table with numeric ID references, improving performance and storage efficiency.

## Table Structure

```sql
CREATE TABLE IF NOT EXISTS lemmaTable (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lemma VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lemma (lemma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Fields
- **id**: Auto-incrementing primary key used as reference in sentences.lemmaList
- **lemma**: The actual lemma string (e.g., "zullen", "we", "kop", "onder")
- **created_at**: Timestamp when the lemma was first added
- **idx_lemma**: Index for fast lemma lookups

## API Endpoints

### 1. getLemmas.php
- **Purpose**: Fetch all sentences with their lemma data for AI processing
- **Method**: GET
- **Returns**: JSON array of sentences with:
  - `id`: Sentence ID
  - `zinString`: The sentence text
  - `lemmaList`: Array of lemma strings (automatically converted from IDs)

### 2. updateLemmas.php
- **Purpose**: Update lemmas for a specific sentence
- **Method**: POST
- **Request body**:
  ```json
  {
    "sentenceId": 123,
    "lemmas": ["zullen", "we", "kop", "onder"]
  }
  ```
- **Process**:
  1. Checks if each lemma exists in lemmaTable
  2. Inserts new lemmas if needed
  3. Updates sentence.lemmaList with array of IDs
- **Returns**: Success status with lemma IDs

## Migration
Use `migrateLemmas.php` to convert existing lemma string arrays to ID arrays:
```bash
php migrateLemmas.php
```

## Example Data Flow

### Before (old format):
```json
{
  "zinString": "Zullen we de kop eronder",
  "lemmaList": ["zullen", "we", "kop", "onder"]
}
```

### After (new format in database):
```json
{
  "zinString": "Zullen we de kop eronder",
  "lemmaList": [232, 1232, 42, 121]
}
```

### API Response (getLemmas.php):
```json
{
  "zinString": "Zullen we de kop eronder",
  "lemmaList": ["zullen", "we", "kop", "onder"]
}
```

## Van Dale Integration for Quality Assurance

### Using Van Dale as Authoritative Source
When processing lemmas, AI agents should use **Van Dale** (vandale.nl) as the primary reference for:

1. **Spelling Verification**: Check correct Dutch spelling
2. **Lemma Validation**: Confirm base forms of words
3. **Morphological Analysis**: Verify verb conjugations, plural forms, adjective inflections

### Van Dale Lookup Process
```python
# Example Van Dale verification workflow
def verify_with_vandale(word: str) -> str:
    """
    1. Check spelling against Van Dale dictionary
    2. Verify lemma form is canonical
    3. Return corrected/verified lemma
    """
    # Common Van Dale-verified corrections:
    corrections = {
        'misschi': 'misschien',        # spelling correction
        'heb': 'hebben',               # verb conjugation
        'schone': 'schoon',            # adjective inflection
        'kinderen': 'kind',            # irregular plural
        'auto\'s': 'auto',             # regular plural
    }
    return corrections.get(word.lower(), word)
```

### Quality Standards
- **Diminutives**: Preserve -je, -tje endings (huisje, boekje)
- **Compounds**: Keep separable verbs intact (aankleden, opruimen)
- **Plurals**: Convert to singular except for lexicalized plurals
- **Verbs**: Always use infinitive form as lemma
- **Adjectives**: Use uninflected base form

### Verification Sources
1. **Primary**: Van Dale Nederlands woordenboek (vandale.nl)
2. **Secondary**: ANW - Algemeen Nederlands Woordenboek (anw.ivdnt.org)
3. **Backup**: OpenTaal Dutch word lists

## Benefits
1. **Performance**: Faster lookups using numeric IDs
2. **Storage**: Reduced database size by eliminating duplicate lemma strings
3. **Consistency**: Centralized lemma management with Van Dale validation
4. **Accuracy**: Improved linguistic quality through authoritative spell checking
5. **Scalability**: Efficient handling of large datasets