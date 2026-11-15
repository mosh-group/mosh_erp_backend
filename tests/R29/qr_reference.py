"""Compare local production QR encoding with python-qrcode; no network or server.
Run with Python 3, qrcode and PHP in PATH. Matrices are compared, not just SVG existence.
"""
from pathlib import Path
import json, subprocess, sys
import qrcode
from qrcode.util import QRData, MODE_8BIT_BYTE
b=Path(__file__).resolve().parents[2]
php="require "+repr(str(b/'app/Services/R29/Rules.php'))+";require "+repr(str(b/'app/Services/R29/QrCode.php'))+";echo json_encode(App\\Services\\R29\\QrCode::matrix(stream_get_contents(STDIN)));"
checks=[]
for size in [1,14,15,26,42,62,84,106,122,152,180,210]:
    text='x'*size
    actual=json.loads(subprocess.check_output(['php','-r',php],input=text.encode(),timeout=10))
    reference=qrcode.QRCode(error_correction=qrcode.constants.ERROR_CORRECT_M,border=0,mask_pattern=0)
    reference.add_data(QRData(text,mode=MODE_8BIT_BYTE),optimize=0);reference.make(fit=True)
    checks.append({'length':size,'version':reference.version,'matches_reference':actual==reference.get_matrix()})
print(json.dumps(checks,indent=2))
if not all(c['matches_reference'] for c in checks):sys.exit(1)
