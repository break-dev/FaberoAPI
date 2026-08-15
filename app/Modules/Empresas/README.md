# Módulo API: Empresas (Deep Dive)

Gestiona la identidad corporativa de las unidades de negocio y contratistas que operan dentro del sistema.

## 🛠 Componentes del Módulo

### 1. Controlador (`EmpresasController`)

- **`crear_empresa`**:
    - **Validación**: Valida el RUC (único) y recibe el logotipo corporativo.
- **`actualizar_logo`**:
    - **Validación**: Verifica la integridad del archivo de imagen antes de la actualización.

### 2. Servicio de Identidad Corporativa (`EmpresasService`)

- **`crear_empresa`**:
    - **Validación de Unicidad**: Implementa un bloqueo por RUC para evitar la duplicidad de entidades legales.
    - **Gestión de Branding**: Utiliza el `ArchivoHelper` para almacenar los logos en una carpeta dedicada (`logos-empresas`).
    - **Normalización de URL**: Al listar las empresas, el servicio transforma las rutas relativas en URLs absolutas utilizando el helper `asset()`, asegurando que el frontend pueda renderizar las imágenes sin lógica adicional de paths.
- **`actualizar_logo`**:
    - Permite el refresco de la identidad visual de la empresa sin afectar sus datos fiscales o históricos.

### 3. Capa de Datos (`EmpresasData`)

- **SQL de Integridad**:
    - `verificar_ruc_duplicado`: Consulta rápida al maestro de empresas.
- **Persistencia**:
    - `crear_empresa`: Registra el RUC, la razón social y opcionalmente el logotipo corporativo.

### Consideraciones
- **RUC Obligatorio**: No se permite el registro de empresas sin un número de identificación fiscal válido.

## 📂 Esquema de Base de Datos Relacionada

- `empresa`: Maestro de entidades legales.
- `labor_mina`: Vinculación con frentes de trabajo.
- `almacen_mina`: Relación con la infraestructura de despacho.
