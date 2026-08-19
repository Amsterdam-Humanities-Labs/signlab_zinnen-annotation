from db_credentials import DB_PASSWORD
import mysql.connector
import pandas as pd
import json

# Database connection details
db_config = {
    'user': 'user',
    'password': DB_PASSWORD,
    'host': 'signlab-db',
    'database': 'admin_gebarenoverleg'
}

# Connect to the database
conn = mysql.connector.connect(**db_config)
cursor = conn.cursor()

# Load the CSV file into a pandas DataFrame
df = pd.read_csv('/web/zin/zin.csv', encoding='latin1', delimiter=';')  # Specify the delimiter as semicolon

# Retrieve all existing zinArray from the database
cursor.execute("SELECT zinArray FROM sentences")
existing_sentences = cursor.fetchall()

# Convert the results into a list of sentence strings (normalize them)
existing_sentence_arrays = [' '.join(json.loads(item[0])) for item in existing_sentences if item[0] is not None and isinstance(json.loads(item[0]), list)]

for lala in existing_sentence_arrays:
    print(lala)
    

# for row in df:
#     print(row)

add_list = {}
#compare the two lists
#when there is no match, add to new list

for _, row in df.iterrows():
    sentence = row['Zin']
    context = row['Context']
    name = row['Naam']

    if sentence not in existing_sentence_arrays:
        add_list[sentence] = {
            'context': context,
            'name': name
        }
        
# print(add_list)
# #count the list
# print(len(add_list))

#forloop to add the new sentences to the database
for sentence, details in add_list.items():
    zin_array = json.dumps(sentence.split())
    glos_array = json.dumps([""] * len(sentence.split()))  # Empty gloss array
    thema = details['context']
    name = details['name']
    
    # Determine userid based on the value of 'name'
    if isinstance(name, str) and name.lower() == 'henrianne':
        userid = 14
    elif isinstance(name, str) and name.lower() == 'diego':
        userid = 15
    elif isinstance(name, str) and name.lower() == 'manouk':
        userid = 16
    elif isinstance(name, str) and name.lower() == 'tobias':
        userid = 7
    else:
        userid = None  # Set userid to NULL if name is empty or not recognized

    # print(zin_array, thema, userid)
    if userid is not None:
        try:
            insert_sql = "INSERT INTO sentences (zinArray, glosArray, thema, userid) VALUES (%s, %s, %s, %s)"
            cursor.execute(insert_sql, (zin_array, glos_array, thema, userid))
            conn.commit()
        except mysql.connector.Error as err:
            print(f"Error: {err}")
            conn.rollback()
    
# print(existing_sentence_arrays)
# # Function to normalize and compare sentences
# def normalize_sentence(sentence):
#     lala = [' '.join(sentence.split())]
#     print(lala)
#     return lala

# def is_duplicate_sentence(new_sentence, existing_sentences):
#     normalized_new_sentence = normalize_sentence(new_sentence)
#     return normalized_new_sentence in existing_sentences

# # Process the sentences
# success_count = 0
# failed_count = 0
# skipped_sentences = []
# added_sentences = []

# for _, row in df.iterrows():
#     sentence = row['Zin']
#     context = row['Context']
#     name = row['Naam']
    
#     # Determine userid based on the value of 'name'
#     if isinstance(name, str) and name.lower() == 'henrianne':
#         userid = 14
#     elif isinstance(name, str) and name.lower() == 'diego':
#         userid = 15
#     elif isinstance(name, str) and name.lower() == 'manouk':
#         userid = 16
#     elif isinstance(name, str) and name.lower() == 'tobias':
#         userid = 7
#     else:
#         userid = None  # Set userid to NULL if name is empty or not recognized

#     thema = context  # Using Context as the theme

#     if not sentence or pd.isna(context):  # Skip if there's no sentence or no context
#         continue
    
#     if not userid:  # Skip if the name is not recognized
#         skipped_sentences.append(sentence)
#         continue

#     # Check for duplicates using Python comparison
#     if is_duplicate_sentence(sentence, existing_sentence_arrays):
#         skipped_sentences.append(sentence)
#         # print(f"Skipping duplicate sentence: {sentence}")
#     else:
#         zin_array = json.dumps(normalize_sentence(sentence))
#         glos_array = json.dumps([""] * len(sentence.split()))  # Empty gloss array
#         print(zin_array)

#         # try:
#         #     insert_sql = "INSERT INTO sentences (zinArray, glosArray, thema, userid) VALUES (%s, %s, %s, %s)"
#         #     cursor.execute(insert_sql, (zin_array, glos_array, thema, userid))
#         #     conn.commit()
#         #     success_count += 1
#         #     added_sentences.append(sentence)
#         #     # Also update the existing sentence list
#         #     existing_sentence_arrays.append(normalize_sentence(sentence))
#         # except mysql.connector.Error as err:
#         #     failed_count += 1
#         #     print(f"Error: {err}")
#         #     conn.rollback()

# # Close the cursor and connection
# cursor.close()
# conn.close()

# # Output the results
# print(f"Successfully added sentences: {success_count}")
# print(f"Failed to add sentences: {failed_count}")
# print(f"Skipped sentences (duplicates, missing context, or invalid name): {len(skipped_sentences)}")
