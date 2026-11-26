# Désinstallation

```bash
./scripts/uninstall.sh
```

Les conteneurs s'arrêtent. Le volume (base et archives) est conservé.

```bash
./scripts/uninstall.sh --purge
```

supprime aussi ce volume. Les factures déjà déposées chez SUPER PDP et les champs Invoice Ninja ne sont pas effacés : retirez le webhook Invoice Ninja et, si vous changez de plateforme, l'annuaire, dans les interfaces correspondantes. Ce logiciel ne modifie pas un mandat tout seul.
