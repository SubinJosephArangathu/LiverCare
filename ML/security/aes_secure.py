from Crypto.Cipher import AES
from Crypto.Random import get_random_bytes
import base64

key = b"1234567890ABCDEF1234567890ABCDEF"  # 32-byte key for AES-256

def pad(data):
    return data + " " * (16 - len(data) % 16)

def encrypt_text(text):
    text = pad(text)
    iv = get_random_bytes(16)
    cipher = AES.new(key, AES.MODE_CBC, iv)
    encrypted = cipher.encrypt(text.encode('utf-8'))
    return base64.b64encode(iv + encrypted).decode('utf-8')

def decrypt_text(encrypted_text):
    encrypted_data = base64.b64decode(encrypted_text)
    iv = encrypted_data[:16]
    cipher = AES.new(key, AES.MODE_CBC, iv)
    decrypted = cipher.decrypt(encrypted_data[16:]).decode('utf-8').rstrip()
    return decrypted
