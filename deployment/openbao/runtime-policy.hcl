# Attached only to the renewable Roundcube Agent AppRole token.
path "kv/data/roundcube/config" {
  capabilities = ["read"]
}

path "kv/data/roundcube/mailboxes/*" {
  capabilities = ["read"]
}
