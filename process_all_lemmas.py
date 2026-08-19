#!/usr/bin/env python3
import json
import requests
import time

def get_correct_lemma(word, current_lemma):
    """Determine the correct lemma for a Dutch word"""
    
    # Common verb conjugations to infinitive
    verb_mappings = {
        'heb': 'hebben',
        'hebt': 'hebben',
        'heeft': 'hebben',
        'had': 'hebben',
        'hadden': 'hebben',
        'gehad': 'hebben',
        'doe': 'doen',
        'doet': 'doen',
        'deed': 'doen',
        'deden': 'doen',
        'gedaan': 'doen',
        'ga': 'gaan',
        'gaat': 'gaan',
        'ging': 'gaan',
        'gingen': 'gaan',
        'gegaan': 'gaan',
        'aangetrokken': 'aantrekken',
        'moet': 'moeten',
        'schone': 'schoon',
        'lange': 'lang',
        'mooie': 'mooi',
        'grote': 'groot',
        'kleine': 'klein',
        'nieuwe': 'nieuw',
        'oude': 'oud',
        'goede': 'goed',
        'eerste': 'eerst',
        'laatste': 'laatst',
        'ben': 'zijn',
        'bent': 'zijn',
        'is': 'zijn',
        'was': 'zijn',
        'waren': 'zijn',
        'geweest': 'zijn',
        'kom': 'komen',
        'komt': 'komen',
        'kwam': 'komen',
        'kwamen': 'komen',
        'gekomen': 'komen',
        'zie': 'zien',
        'ziet': 'zien',
        'zag': 'zien',
        'zagen': 'zien',
        'gezien': 'zien',
        'maak': 'maken',
        'maakt': 'maken',
        'maakte': 'maken',
        'maakten': 'maken',
        'gemaakt': 'maken'
    }
    
    # Plural nouns to singular
    plural_mappings = {
        'schoenen': 'schoen',
        'armen': 'arm',
        'mouwen': 'mouw',
        'kinderen': 'kind',
        'olifanten': 'olifant',
        'huizen': 'huis',
        'boeken': 'boek',
        'stoelen': 'stoel',
        'tafels': 'tafel',
        'mensen': 'mens',
        'dagen': 'dag',
        'weken': 'week',
        'jaren': 'jaar',
        'ogen': 'oog',
        'oren': 'oor',
        'handen': 'hand',
        'voeten': 'voet',
        'benen': 'been'
    }
    
    # Check verb mappings
    if word in verb_mappings:
        return verb_mappings[word]
    
    # Check plural mappings
    if word in plural_mappings:
        return plural_mappings[word]
    
    # Articles and pronouns remain lowercase
    if word in ['de', 'het', 'een']:
        return word
        
    # Keep current lemma if no correction needed
    return current_lemma

def analyze_sentence(sentence):
    """Analyze a single sentence and return corrections if needed"""
    sentence_id = sentence['id']
    zin_string = sentence['zinString']
    current_lemmas = sentence['lemmaList']
    
    # Tokenize the sentence
    words = zin_string.replace('?', '').replace('.', '').replace(',', '').replace('!', '').split()
    
    # Check if word count matches
    if len(words) != len(current_lemmas):
        print(f"WARNING: Word count mismatch for sentence {sentence_id}")
        return None
        
    # Analyze each word and its lemma
    corrected_lemmas = []
    needs_correction = False
    
    for i, (word, current_lemma) in enumerate(zip(words, current_lemmas)):
        correct_lemma = get_correct_lemma(word.lower(), current_lemma)
        corrected_lemmas.append(correct_lemma)
        
        if correct_lemma != current_lemma:
            needs_correction = True
            
    if needs_correction:
        return {
            'sentenceId': sentence_id,
            'lemmas': corrected_lemmas
        }
    
    return None

def process_page(page_num):
    """Process a single page of sentences"""
    url = f"https://signcollect.nl/zin/getLemmas.php?page={page_num}"
    
    try:
        response = requests.get(url)
        data = response.json()
        
        if not data['success']:
            print(f"Failed to fetch page {page_num}")
            return False, False
            
        sentences = data['data']
        has_next = data['pagination']['has_next']
        
        corrections = []
        for sentence in sentences:
            correction = analyze_sentence(sentence)
            if correction:
                corrections.append(correction)
        
        # Update corrections
        if corrections:
            print(f"\nPage {page_num}: Found {len(corrections)} sentences needing correction")
            
            for correction in corrections:
                update_url = "https://signcollect.nl/zin/updateLemmas.php"
                headers = {"Content-Type": "application/json"}
                
                update_response = requests.post(update_url, json=correction, headers=headers)
                if update_response.status_code == 200:
                    result = update_response.json()
                    if result['success']:
                        print(f"  ✓ Updated sentence {correction['sentenceId']}")
                    else:
                        print(f"  ✗ Failed to update sentence {correction['sentenceId']}")
                else:
                    print(f"  ✗ HTTP error {update_response.status_code} for sentence {correction['sentenceId']}")
                
                # Small delay to avoid overwhelming the server
                time.sleep(0.1)
        else:
            print(f"\nPage {page_num}: No corrections needed")
            
        return True, has_next
        
    except Exception as e:
        print(f"Error processing page {page_num}: {str(e)}")
        return False, False

def main():
    """Process all pages of sentences"""
    print("=== Dutch Lemmatization Processor ===")
    print("Starting to process sentences...\n")
    
    page = 1
    total_processed = 0
    
    while True:
        success, has_next = process_page(page)
        
        if not success:
            print(f"\nStopping due to error on page {page}")
            break
            
        total_processed += 10  # 10 sentences per page
        
        if not has_next:
            print(f"\nCompleted! Processed all {total_processed} sentences.")
            break
            
        # Process only first 5 pages as a demonstration
        # Remove this condition to process all 421 pages
        if page >= 5:
            print(f"\nDemo mode: Stopping after {page} pages. Remove this limit to process all pages.")
            break
            
        page += 1
        time.sleep(0.5)  # Be polite to the server

if __name__ == "__main__":
    main()