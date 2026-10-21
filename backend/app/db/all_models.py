"""Import every model module so metadata is complete (Alembic, tests)."""

from app.agent import models as agent_models  # noqa: F401
from app.core import models as core_models  # noqa: F401
from app.db.base import Base
from app.modules.inventory import models as inventory_models  # noqa: F401
from app.modules.production import models as production_models  # noqa: F401
from app.modules.supply import models as supply_models  # noqa: F401

SCHEMAS = ("core", "agent", "production", "inventory", "supply")
metadata = Base.metadata
