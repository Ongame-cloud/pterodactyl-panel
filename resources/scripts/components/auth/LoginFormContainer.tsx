import React, { forwardRef } from 'react';
import { Form } from 'formik';
import styled from 'styled-components/macro';
import FlashMessageRender from '@/components/FlashMessageRender';
import tw from 'twin.macro';

type Props = React.DetailedHTMLProps<React.FormHTMLAttributes<HTMLFormElement>, HTMLFormElement> & {
    title?: string;
};

const Container = styled.div`
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    height: 100vh;
    overflow: hidden;
    background-color: oklch(0.1957 0 0);
    padding: 1rem;
`;

const Card = styled.div`
    background-color: oklch(0.208 0.042 265.755);
    border: 1px solid oklch(1 0 0 / 10%);
    border-radius: 12px;
    padding: 3rem 2.5rem;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.2);
`;

const Logo = styled.img`
    height: 3rem;
    margin: 0 auto 2rem;
    display: block;
`;

const Title = styled.h2`
    color: #ffffff;
    font-size: 1.5rem;
    font-weight: 600;
    text-align: center;
    margin: 0 0 0.5rem 0;
`;

const Subtitle = styled.p`
    color: #9ca3af;
    font-size: 0.875rem;
    text-align: center;
    margin: 0 0 2rem 0;
`;

export default forwardRef<HTMLFormElement, Props>(({ title, ...props }, ref) => (
    <Container>
        <div style={{ width: '100%', maxWidth: '420px' }}>
            <FlashMessageRender css={tw`mb-4`} />
            <Form {...props} ref={ref}>
                <Card>
                    <Logo src="https://dev.ogc.nz/img/svg/logo.svg" alt="Ongamecloud" />
                    {title && <Title>{title}</Title>}
                    <Subtitle>Ongamecloud Pterodactyl panel</Subtitle>
                    {props.children}
                </Card>
            </Form>
        </div>
    </Container>
));
