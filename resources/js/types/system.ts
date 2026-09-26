export type SystemStatus = {
    application: string;
    version: string;
    runtime: 'desktop' | 'browser';
    database: { driver: string; connected: boolean };
};
