#!/usr/bin/env python3
import json
import re
from typing import List, Dict, Tuple

class DutchLemmatizer:
    def __init__(self):
        # Common verb conjugations to infinitive
        self.verb_forms = {
            # hebben
            'heb': 'hebben', 'hebt': 'hebben', 'heeft': 'hebben', 'had': 'hebben', 'hadden': 'hebben',
            # zijn
            'ben': 'zijn', 'bent': 'zijn', 'is': 'zijn', 'was': 'zijn', 'waren': 'zijn',
            # gaan
            'ga': 'gaan', 'gaat': 'gaan', 'ging': 'gaan', 'gingen': 'gaan', 'gegaan': 'gaan',
            # doen
            'doe': 'doen', 'doet': 'doen', 'deed': 'doen', 'deden': 'doen', 'gedaan': 'doen',
            # moeten
            'moet': 'moeten', 'moest': 'moeten', 'moesten': 'moeten',
            # kunnen
            'kan': 'kunnen', 'kunt': 'kunnen', 'kon': 'kunnen', 'konden': 'kunnen',
            # willen
            'wil': 'willen', 'wilt': 'willen', 'wilde': 'willen', 'wilden': 'willen',
            # komen
            'kom': 'komen', 'komt': 'komen', 'kwam': 'komen', 'kwamen': 'komen', 'gekomen': 'komen',
            # zien
            'zie': 'zien', 'ziet': 'zien', 'zag': 'zien', 'zagen': 'zien', 'gezien': 'zien',
            # maken
            'maak': 'maken', 'maakt': 'maken', 'maakte': 'maken', 'maakten': 'maken', 'gemaakt': 'maken',
            # draaien
            'draai': 'draaien', 'draait': 'draaien', 'draaide': 'draaien',
        }
        
        # Past participles to infinitive
        self.participles = {
            'aangetrokken': 'aantrekken', 'aangekleed': 'aankleden', 'aangedaan': 'aandoen',
            'gedaan': 'doen', 'gezien': 'zien', 'geweest': 'zijn', 'gegaan': 'gaan',
            'gekomen': 'komen', 'gemaakt': 'maken', 'gevraagd': 'vragen', 'gebracht': 'brengen',
            'gedacht': 'denken', 'gekregen': 'krijgen', 'gegeven': 'geven', 'genomen': 'nemen',
            'gehoord': 'horen', 'gezegd': 'zeggen', 'geworden': 'worden', 'gevonden': 'vinden',
            'begonnen': 'beginnen', 'gebleven': 'blijven', 'gelezen': 'lezen', 'geschreven': 'schrijven',
        }
        
        # Adjective inflections
        self.adjective_inflections = {
            'schone': 'schoon', 'lange': 'lang', 'grote': 'groot', 'kleine': 'klein',
            'nieuwe': 'nieuw', 'oude': 'oud', 'goede': 'goed', 'mooie': 'mooi',
            'leuke': 'leuk', 'eerste': 'eerste', 'laatste': 'laatste', 'hele': 'heel',
            'verschillende': 'verschillend', 'belangrijke': 'belangrijk',
        }

    def lemmatize_word(self, word: str) -> str:
        """Lemmatize a single Dutch word"""
        word_lower = word.lower()
        
        # Check verb forms
        if word_lower in self.verb_forms:
            return self.verb_forms[word_lower]
        
        # Check past participles
        if word_lower in self.participles:
            return self.participles[word_lower]
        
        # Check adjective inflections
        if word_lower in self.adjective_inflections:
            return self.adjective_inflections[word_lower]
        
        # Special cases for nouns
        if word_lower == 'armen':
            return 'arm'
        elif word_lower == 'mouwen':
            return 'mouw'
        elif word_lower == 'schoenen':
            return 'schoen'
        
        # Return the original word if no rule applies
        return word_lower

    def lemmatize_sentence(self, sentence: str, current_lemmas: List[str]) -> Tuple[List[str], bool]:
        """
        Lemmatize a Dutch sentence and compare with current lemmas
        Returns: (new_lemmas, needs_update)
        """
        # Tokenize sentence (simple split, could be improved)
        words = sentence.split()
        
        # Remove punctuation from words
        clean_words = []
        for word in words:
            clean_word = re.sub(r'[.,!?;:]$', '', word)
            clean_words.append(clean_word)
        
        # Lemmatize each word
        new_lemmas = []
        for word in clean_words:
            lemma = self.lemmatize_word(word)
            new_lemmas.append(lemma)
        
        # Check if update is needed
        needs_update = False
        if len(new_lemmas) == len(current_lemmas):
            for i, (new, current) in enumerate(zip(new_lemmas, current_lemmas)):
                if new != current:
                    needs_update = True
                    break
        else:
            needs_update = True
        
        return new_lemmas, needs_update

# Test data from first page
test_data = [
    {
        "id": "1",
        "zinString": "Doe je je jas aan?",
        "lemmaList": ["doen", "je", "je", "jas", "aan"]
    },
    {
        "id": "4",
        "zinString": "Doe maar je armen omhoog.",
        "lemmaList": ["doen", "maar", "je", "arm", "omhoog"]
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

if __name__ == "__main__":
    lemmatizer = DutchLemmatizer()

    print("Testing lemmatizer on sample sentences:")
    for sentence_data in test_data:
        sentence_id = sentence_data['id']
        sentence_text = sentence_data['zinString']
        current_lemmas = sentence_data['lemmaList']
        
        new_lemmas, needs_update = lemmatizer.lemmatize_sentence(sentence_text, current_lemmas)
        
        print(f"\nSentence {sentence_id}: {sentence_text}")
        print(f"Current: {current_lemmas}")
        print(f"New:     {new_lemmas}")
        print(f"Update needed: {needs_update}")