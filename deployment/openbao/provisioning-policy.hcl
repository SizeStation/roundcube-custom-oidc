# Attached to a separate, short-lived administrative identity. Never mount its
# token in the web or Agent service.
path "kv/data/roundcube/mailboxes/*" {
  capabilities = ["create", "update", "read", "delete"]
}

path "kv/metadata/roundcube/mailboxes/*" {
  capabilities = ["read", "list", "delete"]
}
