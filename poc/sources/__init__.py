from . import datatourisme, openagenda, paris_qfap, ticketmaster
from .awin import billetreduc, fnac

# Ordre = ordre de valeur attendu (plan d'étapes, étape 16)
SOURCES = {m.NAME: m for m in (billetreduc, fnac, datatourisme, openagenda, ticketmaster, paris_qfap)}
