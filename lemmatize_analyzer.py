#!/usr/bin/env python3
import json
import requests

def analyze_lemmatization(sentence_data):
    """Analyze Dutch sentences and identify lemmatization corrections"""
    corrections = []
    
    for sentence in sentence_data:
        sentence_id = sentence['id']
        zin_string = sentence['zinString']
        current_lemmas = sentence['lemmaList']
        
        # Tokenize the sentence
        words = zin_string.replace('?', '').replace('.', '').replace(',', '').split()
        
        # Check if word count matches
        if len(words) != len(current_lemmas):
            print(f"WARNING: Word count mismatch for sentence {sentence_id}")
            continue
            
        # Analyze each word and its lemma
        corrected_lemmas = []
        needs_correction = False
        
        for i, (word, current_lemma) in enumerate(zip(words, current_lemmas)):
            correct_lemma = get_correct_lemma(word.lower(), current_lemma)
            corrected_lemmas.append(correct_lemma)
            
            if correct_lemma != current_lemma:
                needs_correction = True
                
        if needs_correction:
            corrections.append({
                'sentenceId': sentence_id,
                'original': zin_string,
                'current_lemmas': current_lemmas,
                'corrected_lemmas': corrected_lemmas
            })
    
    return corrections

def get_correct_lemma(word, current_lemma):
    """Determine the correct lemma for a Dutch word"""
    
    # Common verb conjugations to infinitive
    verb_mappings = {
        'heb': 'hebben',
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
        'lange': 'lang'
    }
    
    # Plural nouns to singular
    if word.endswith('en') and word not in ['een', 'doen', 'gaan', 'zien', 'hebben', 'regenen', 'aandoen', 'staan', 'zitten', 'aankleden']:
        # Check common plurals
        if word == 'schoenen':
            return 'schoen'
        elif word == 'armen':
            return 'arm'
        elif word == 'mouwen':
            return 'mouw'
    
    # Check verb mappings
    if word in verb_mappings:
        return verb_mappings[word]
    
    # Articles and pronouns remain lowercase
    if word in ['de', 'het', 'een']:
        return word
        
    # Keep current lemma if no correction needed
    return current_lemma

# Load the data
data = {
  "data": [
    {
      "id": "1",
      "zinString": "Doe je je jas aan?",
      "lemmaList": ["doen", "je", "je", "jas", "aan"]
    },
    {
      "id": "2",
      "zinString": "Doe je schoenen aan.",
      "lemmaList": ["doen", "je", "schoenen", "aan"]
    },
    {
      "id": "4",
      "zinString": "Doe maar je armen omhoog.",
      "lemmaList": ["doen", "maar", "je", "arm", "omhoog"]
    },
    {
      "id": "5",
      "zinString": "Draai je maar om.",
      "lemmaList": ["draaien", "je", "maar", "om"]
    },
    {
      "id": "7",
      "zinString": "Even een schone onderbroek aandoen.",
      "lemmaList": ["even", "een", "schone", "onderbroek", "aandoen"]
    },
    {
      "id": "8",
      "zinString": "ga je maar aankleden",
      "lemmaList": ["gaan", "je", "maar", "aankleden"]
    },
    {
      "id": "9",
      "zinString": "Ga maar even staan.",
      "lemmaList": ["gaan", "maar", "even", "staan"]
    },
    {
      "id": "10",
      "zinString": "Ga maar zitten",
      "lemmaList": ["gaan", "maar", "zitten"]
    },
    {
      "id": "11",
      "zinString": "Heb je een schone onderbroek aangetrokken?",
      "lemmaList": ["heb", "je", "een", "schone", "onderbroek", "aangetrokken"]
    },
    {
      "id": "12",
      "zinString": "het gaat regenen vandaag, dus je moet lange mouwen aan.",
      "lemmaList": ["het", "gaan", "regenen", "vandaag", "dus", "je", "moet", "lang", "mouwen", "aan"]
    }
  ]
}

# Analyze the sentences
corrections = analyze_lemmatization(data['data'])

# Print results
print("=== Lemmatization Analysis Results ===\n")
if corrections:
    print(f"Found {len(corrections)} sentences needing correction:\n")
    for correction in corrections:
        print(f"Sentence ID: {correction['sentenceId']}")
        print(f"Text: {correction['original']}")
        print(f"Current lemmas: {correction['current_lemmas']}")
        print(f"Corrected lemmas: {correction['corrected_lemmas']}")
        print("-" * 50)
else:
    print("No corrections needed for the analyzed sentences.")