# SAP Purchase Order Portal

Portal web para consulta e atualização de linhas de pedidos de compra no SAP Business One, integrado ao Service Layer.

A aplicação permite editar quantidades e datas de entrega individualmente ou por importação XLSX, com validação, prévia das alterações, confirmação e registro de auditoria.

## Visão geral

Atualizar pedidos de compra em lote exige mais do que receber uma planilha: é necessário identificar corretamente cada linha, validar os valores informados, respeitar o calendário comercial e apresentar o resultado antes de aplicar as alterações.

Este projeto reúne essas etapas em uma interface web, mantendo o SAP Business One como sistema de origem e destino dos pedidos.

O fluxo de trabalho é:

```text
Consulta dos pedidos
        ↓
Edição manual ou importação XLSX
        ↓
Validação e prévia
        ↓
Confirmação do usuário
        ↓
Atualização pelo Service Layer
        ↓
Registro de auditoria
```

## Funcionalidades

### Consulta de pedidos

- Listagem paginada de pedidos de compra e suas linhas.
- Filtros de consulta.
- Exibição de fornecedor, item, descrição, quantidade e data de entrega.
- Exportação de planilha considerando os filtros selecionados.
- Consulta em lote para reduzir o número de chamadas necessárias na listagem.

### Edição e importação

- Edição manual de quantidades e datas de entrega.
- Download de modelo XLSX.
- Importação de alterações por planilha.
- Validação antes da aplicação.
- Prévia das alterações para conferência.
- Confirmação explícita antes do envio ao SAP.
- Controle de permissão de gravação por empresa.

### Calendário comercial

- Configuração dos dias úteis da semana.
- Cadastro de feriados anuais.
- Cadastro de feriados e paralisações para datas específicas.
- Integração do calendário ao cálculo e à validação de datas de entrega.
- Controle de revisão para identificar mudanças no calendário entre a prévia e a aplicação.

As datas móveis e emendas devem ser cadastradas pelo usuário conforme o calendário adotado pela empresa.

### Autenticação e rastreabilidade

- Login com credenciais do SAP Business One.
- Seleção entre empresas configuradas.
- Sessão de autenticação da aplicação.
- Proteção CSRF nas operações que alteram dados.
- Logs estruturados em JSON.
- Registro de contexto, como empresa, usuário e informações da operação.

## Tecnologias

- PHP
- JavaScript
- HTML e CSS
- SAP Business One Service Layer
- XLSX para importação e exportação de dados
- Persistência em arquivos para calendário e logs

A aplicação foi utilizada em ambiente conteinerizado, com dados persistentes armazenados fora do diretório de código.

## Arquitetura

```text
Navegador
    │
    ▼
Interface web e endpoints PHP
    │
    ├── Autenticação e sessão
    ├── Consulta e atualização de pedidos
    ├── Importação XLSX
    ├── Calendário comercial
    └── Auditoria
    │
    ▼
SAP Business One Service Layer
```

O acesso ao SAP é centralizado em um cliente do Service Layer. As regras de pedidos, calendário e importação ficam separadas dos endpoints HTTP e da interface.

## Estrutura do projeto

```text
.
├── config/
│   ├── app.php.example          # Modelo de configuração da aplicação
│   └── storage.php              # Caminho do armazenamento persistente
│
├── public/
│   ├── api/                     # Endpoints de consulta, prévia e aplicação
│   ├── assets/                  # JavaScript e CSS
│   ├── download-template.php    # Download do modelo XLSX
│   ├── index.php
│   ├── login.php
│   ├── logout.php
│   └── orders.php               # Interface de pedidos
│
├── src/
│   ├── Auth.php                 # Autenticação e proteção CSRF
│   ├── Bootstrap.php            # Inicialização da aplicação
│   ├── BusinessCalendar.php     # Calendário e regras de dias úteis
│   ├── PurchaseOrderService.php # Regras de consulta e atualização
│   ├── ServiceLayerClient.php   # Comunicação com o SAP
│   └── XlsxPurchaseOrderImport.php
│
└── storage/
    ├── config/
    ├── logs/
    └── sessions/
```

O arquivo `config/app.php` contém a configuração local e não é versionado.

## Requisitos de ambiente

Para executar a aplicação, é necessário:

- Um ambiente PHP compatível com o código e com as extensões utilizadas.
- Um servidor web configurado para executar PHP.
- Acesso de rede ao SAP Business One Service Layer.
- Uma empresa SAP e um usuário com permissões adequadas.
- Um diretório de armazenamento com permissão de leitura e escrita para o processo PHP.

Este repositório não inclui uma instância do SAP Business One. Para testar o fluxo integrado, é necessário acesso a um ambiente SAP.

## Configuração

### 1. Obtenha o código

```bash
git clone [https://github.com/Luke-uhd/sap-purchase-order-portal.git](https://github.com/Luke-uhd/sap-purchase-order-portal.git)
cd sap-purchase-order-portal
```

### 2. Crie a configuração local

```bash
cp config/app.php.example config/app.php
```

Edite `config/app.php` e configure:

- URL do Service Layer.
- Opções de verificação do certificado TLS.
- Empresas disponíveis no login.
- Empresas que permitem gravação.
- Tamanho padrão e limite de paginação.

Exemplo de configuração de empresas:

```php
'allowed_companies' => [
    'SBO_DEMO' => 'SAP B1 - Demonstração',
],

'write_enabled_companies' => [
    'SBO_DEMO' => false,
],
```

Comece com a gravação desabilitada e habilite-a somente após validar o funcionamento em uma empresa de testes.

Não publique o arquivo de configuração local nem credenciais do ambiente.

### 3. Configure o armazenamento

O caminho utilizado pela aplicação é definido em `config/storage.php`.

No ambiente original de implantação, o arquivo aponta para:

```php
return '/customization/sap-data';
```

Ajuste esse caminho conforme seu ambiente e garanta que o processo PHP tenha as permissões necessárias.

Em execução com containers, utilize um volume persistente para preservar os dados entre atualizações e recriações do container.

A pasta `storage/` do repositório contém apenas a estrutura de referência. O caminho efetivamente utilizado deve ser conferido na configuração e na inicialização da aplicação.

### 4. Configure o servidor web

Configure o diretório `public/` como raiz pública da aplicação.

Os diretórios de configuração, código interno e armazenamento não devem ficar disponíveis para download pelo navegador.

### 5. Valide em ambiente de testes

Antes de habilitar alterações em produção:

1. Verifique o login e a seleção de empresa.
2. Confira a listagem, os filtros e a paginação.
3. Configure os dias úteis e feriados.
4. Teste uma alteração manual.
5. Teste a importação de uma planilha pequena.
6. Confira a prévia e o resultado no SAP.
7. Verifique os registros de auditoria.

## Como utilizar

1. Selecione a empresa e faça login.
2. Localize os pedidos usando os filtros disponíveis.
3. Edite as linhas na interface ou baixe o modelo XLSX.
4. Para alterações em lote, preencha e importe a planilha.
5. Revise a prévia apresentada.
6. Confirme a aplicação das alterações.
7. Confira o resultado da operação.

O calendário comercial pode ser configurado pela interface para refletir os dias de trabalho e as paralisações da empresa.

## Decisões de implementação

### Prévia antes da gravação

A consulta e a validação são separadas da aplicação das alterações. Isso permite ao usuário conferir o que será enviado antes de modificar os pedidos.

### Consulta em lote

A listagem foi ajustada para consultar pedidos e linhas em lote, evitando uma chamada individual para cada pedido.

### Calendário separado do código

Dias úteis e feriados são configuráveis pela interface, sem necessidade de alterar o código a cada mudança de calendário.

### Persistência separada da publicação

Calendários e logs ficam em armazenamento persistente, separado dos arquivos publicados da aplicação.

### Configuração local fora do Git

Endereços de ambiente e nomes reais de empresas SAP ficam no arquivo de configuração local, ignorado pelo versionamento. O repositório disponibiliza um modelo com valores ilustrativos.

## Cuidados e limitações

- A aplicação depende da disponibilidade e das permissões do Service Layer.
- O calendário reflete os feriados cadastrados; não atualiza automaticamente datas móveis ou emendas.
- A configuração de gravação por empresa não substitui as autorizações do usuário no SAP.
- Uma prévia validada não elimina a possibilidade de falha durante a aplicação das alterações.
- Não presuma que uma operação em lote seja uma transação única: confira o retorno da aplicação e o estado final dos pedidos.
- Logs e dados persistentes devem receber controle de acesso e fazer parte da política de backup do ambiente.
- Exemplos de configuração não devem ser usados como configuração de produção sem revisão.

## Contexto do projeto

Projeto desenvolvido a partir de uma necessidade operacional de manutenção de pedidos de compra no SAP Business One.

O foco foi combinar produtividade nas alterações em lote com conferência prévia, regras de calendário, rastreabilidade e separação entre código e dados persistentes.

## Licença

Este projeto é distribuído sob a licença MIT. Consulte o arquivo `LICENSE` para os termos completos.
