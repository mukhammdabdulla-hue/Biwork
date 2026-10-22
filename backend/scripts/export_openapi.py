"""Write the OpenAPI schema to backend/openapi.json (input for the frontend's generated types)."""

import json
import pathlib

from app.main import app

path = pathlib.Path(__file__).resolve().parent.parent / "openapi.json"
path.write_text(json.dumps(app.openapi(), indent=1, ensure_ascii=False))
print(f"wrote {path}")
