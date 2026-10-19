# Exemplos

Execute os exemplos a partir da raiz do projeto:

```bash
php examples/basic-usage.php
php examples/named-connections.php
```

Os exemplos usam SQLite em memória e precisam da extensão `pdo_sqlite`. Cada
script cria suas próprias tabelas e dados temporários.

- `basic-usage.php` demonstra configuração, modelo, `INSERT` e `SELECT` por meio
  de `customQuery()`.
- `named-connections.php` demonstra duas configurações SQLite independentes e
  como selecionar uma delas pelo `$connectionIdentifier` do modelo.