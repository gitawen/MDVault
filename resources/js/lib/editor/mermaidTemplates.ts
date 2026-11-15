export type MermaidTemplate = {
    id: string;
    name: string;
    description: string;
    code: string;
};

export const MERMAID_TEMPLATES: MermaidTemplate[] = [
    {
        id: 'flowchart',
        name: 'Flowchart',
        description:
            'Directional flow diagrams, decision trees, and processes.',
        code: `graph TD
    Start([Start]) --> Process[Process Task]
    Process --> Decision{Is valid?}
    Decision -- Yes --> Success([Complete])
    Decision -- No --> Error([Handle Error])`,
    },
    {
        id: 'sequence',
        name: 'Sequence Diagram',
        description:
            'Actors, participants, and synchronous/asynchronous messages.',
        code: `sequenceDiagram
    autonumber
    actor User
    participant Client
    participant Server
    User->>Client: Open note
    Client->>Server: Request note data
    Server-->>Client: Return note content
    Client-->>User: Display rendered note`,
    },
    {
        id: 'state',
        name: 'State Diagram',
        description: 'State transitions, events, actions, and lifecycle flows.',
        code: `stateDiagram-v2
    [*] --> Draft
    Draft --> Review: Submit
    Review --> Approved: Accept
    Review --> Rejected: Request Changes
    Rejected --> Draft: Edit
    Approved --> [*]`,
    },
    {
        id: 'class',
        name: 'Class Diagram',
        description:
            'Object-oriented classes, attributes, methods, and relationships.',
        code: `classDiagram
    class Vault {
        +String uuid
        +String name
        +Boolean isEncrypted
        +lock()
        +unlock(key)
    }
    class Note {
        +String path
        +String content
        +save()
    }
    Vault "1" *-- "*" Note : contains`,
    },
    {
        id: 'er',
        name: 'Entity Relationship',
        description:
            'Database entities, primary/foreign keys, and relationships.',
        code: `erDiagram
    VAULT ||--o{ NOTE : contains
    NOTE ||--o{ TAG : labeled_with
    NOTE {
        string uuid PK
        string title
        string relative_path
    }`,
    },
    {
        id: 'gantt',
        name: 'Gantt Chart',
        description: 'Project timelines, milestones, tasks, and scheduling.',
        code: `gantt
    title Vault Synchronization Roadmap
    dateFormat YYYY-MM-DD
    section Core
    Index Engine :done, 2026-01-01, 2026-01-15
    Mermaid Integration :active, 2026-01-16, 2026-01-30
    section UI
    Toolbar Updates :2026-02-01, 2026-02-10`,
    },
    {
        id: 'pie',
        name: 'Pie Chart',
        description: 'Proportional distributions and dataset percentages.',
        code: `pie title Note Types in Vault
    "Markdown Notes" : 65
    "Diagrams & Specs" : 20
    "Code Snippets" : 15`,
    },
    {
        id: 'mindmap',
        name: 'Mindmap',
        description:
            'Hierarchical node trees for brainstorming and concept maps.',
        code: `mindmap
  root((MDVault))
    Architecture
      CodeMirror 6
      Marked Preview
      Mermaid Diagrams
    Storage
      Local Filesystem
      AES-GCM Encryption
    Platform
      Desktop Electron
      Web SPA`,
    },
    {
        id: 'gitGraph',
        name: 'Git Graph',
        description: 'Branching, merging, commit histories, and release flows.',
        code: `gitGraph
    commit id: "Initial commit"
    branch feature/mermaid
    checkout feature/mermaid
    commit id: "Add parser"
    commit id: "Add editor dialog"
    checkout main
    merge feature/mermaid id: "Merge diagram feature"`,
    },
];

export function getMermaidTemplate(id: string): MermaidTemplate | undefined {
    return MERMAID_TEMPLATES.find((t) => t.id === id);
}

export const DEFAULT_MERMAID_CODE = MERMAID_TEMPLATES[0].code;
