import React, { forwardRef } from 'react';
import { Form } from 'formik';
import styled from 'styled-components/macro';
import { breakpoint } from '@/theme';
import FlashMessageRender from '@/components/FlashMessageRender';
import tw from 'twin.macro';

type Props = React.DetailedHTMLProps<React.FormHTMLAttributes<HTMLFormElement>, HTMLFormElement> & {
    title?: string;
};

const Container = styled.div`
    ${tw`flex items-center justify-center min-h-screen`}
    background-color: oklch(0.1957 0 0);
    
    ${breakpoint('sm')`
        ${tw`w-full px-4`}
    `};

    ${breakpoint('md')`
        ${tw`w-full`}
    `};

    ${breakpoint('lg')`
        ${tw`w-full`}
    `};

    ${breakpoint('xl')`
        ${tw`w-full`}
    `};
`;

export default forwardRef<HTMLFormElement, Props>(({ title, ...props }, ref) => (
    <Container>
        <div css={tw`w-full max-w-md`}>
            <div css={tw`flex justify-center mb-8`}>
                <img src={'https://dev.ogc.nz/img/svg/logo.svg'} css={tw`h-12`} alt="Logo" />
            </div>
            {title && <h2 css={tw`text-2xl text-center text-white font-semibold mb-2`}>{title}</h2>}
            <p css={tw`text-center text-sm mb-8`} style={{ color: '#d1d5db' }}>Welcome to Ongamecloud</p>
            <FlashMessageRender css={tw`mb-4`} />
            <Form {...props} ref={ref}>
                <div css={tw`rounded-lg p-8 shadow-xl`} style={{ backgroundColor: 'oklch(0.208 0.042 265.755)', border: '1px solid oklch(1 0 0 / 10%)' }}>
                    {props.children}
                </div>
            </Form>
        </div>
    </Container>
));
